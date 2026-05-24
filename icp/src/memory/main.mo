import HashMap "mo:base/HashMap";
import Text "mo:base/Text";
import Time "mo:base/Time";
import Nat "mo:base/Nat";
import Nat16 "mo:base/Nat16";
import Int "mo:base/Int";
import Iter "mo:base/Iter";
import Buffer "mo:base/Buffer";
import Array "mo:base/Array";
import Principal "mo:base/Principal";
import Error "mo:base/Error";
import Types "./types";

actor Memory {

  // Stable storage survives canister upgrades
  stable var memoriesEntries : [(Text, Types.MemoryRecord)] = [];

  // nextId is stable so IDs never collide after an upgrade
  stable var nextId : Nat = 0;

  // Runtime HashMap keyed by record ID
  var memories : HashMap.HashMap<Text, Types.MemoryRecord> = HashMap.fromIter(
    memoriesEntries.vals(),
    100, Text.equal, Text.hash
  );

  system func preupgrade() {
    memoriesEntries := Iter.toArray(memories.entries());
  };

  system func postupgrade() {
    memories := HashMap.fromIter(
      memoriesEntries.vals(),
      100, Text.equal, Text.hash
    );
    memoriesEntries := [];
  };

  // ─── Writes ────────────────────────────────────────────────────────

  // Store a memory record.
  // user_id is always msg.caller — the request body cannot override it.
  // memory_type defaults to #Public if absent.
  // Anonymous principals are rejected: writes must come from an authenticated
  // identity so ownership has a real key behind it. Without this, two signed-out
  // browsers would both write under the shared anonymous principal and one could
  // read the other's private records by satisfying caller == user_id.
  public shared(msg) func store_memory(req : Types.StoreRequest) : async Text {
    if (Principal.isAnonymous(msg.caller)) {
      throw Error.reject("anonymous principal cannot store memories");
    };

    let caller  = Principal.toText(msg.caller);
    let id      = caller # ":" # Nat.toText(nextId);
    nextId += 1;

    let memType = switch (req.memory_type) {
      case (?t) { t };
      case null { #Public };
    };

    let record : Types.MemoryRecord = {
      user_id     = caller;
      session_id  = req.session_id;
      content     = req.content;
      timestamp   = Time.now();
      metadata    = req.metadata;
      memory_type = memType;
    };

    memories.put(id, record);
    id
  };

  // Delete a record — only the owning principal may delete it.
  // Anonymous principals can never be owners (see store_memory) and are
  // rejected here as well, so an anonymous caller cannot delete records
  // even if a legacy anonymous-owned record exists from before this guard.
  public shared(msg) func delete_memory(id : Text) : async Bool {
    if (Principal.isAnonymous(msg.caller)) { return false };

    let caller = Principal.toText(msg.caller);
    switch (memories.get(id)) {
      case (?record) {
        if (record.user_id != caller) { return false };
        ignore memories.remove(id);
        true
      };
      case null { false };
    }
  };

  // ─── Reads ─────────────────────────────────────────────────────────

  // Get memories for a user.
  // Access rules:
  //   Public    — visible to any caller (the agent/adapter reads this way)
  //   Private   — visible only to the record owner (msg.caller == user_id)
  //   Sensitive — same as Private
  //
  // This means the LLM/Laravel can only recall Public memories.
  // Private and Sensitive records are for the user to read directly.
  public shared query(msg) func get_memories(user_id : Text) : async [Types.MemoryResponse] {
    let caller   = Principal.toText(msg.caller);
    // Anonymous callers can never be owners. Without this check, any anonymous
    // caller could satisfy caller == user_id for records that a previous
    // anonymous caller had stored and read their private/sensitive content.
    let isOwner  = not Principal.isAnonymous(msg.caller) and caller == user_id;
    let buf = Buffer.Buffer<Types.MemoryResponse>(10);

    for ((id, record) in memories.entries()) {
      if (record.user_id == user_id) {
        let visible = switch (record.memory_type) {
          case (#Public)    { true    };
          case (#Private)   { isOwner };
          case (#Sensitive) { isOwner };
        };
        if (visible) { buf.add(toResponse(id, record)) };
      };
    };

    Array.sort(Buffer.toArray(buf), byTimestampDesc)
  };

  // Get memories for a specific session (owner only for private/sensitive).
  public shared query(msg) func get_memories_by_session(session_id : Text) : async [Types.MemoryResponse] {
    let caller     = Principal.toText(msg.caller);
    let canBeOwner = not Principal.isAnonymous(msg.caller);
    let buf        = Buffer.Buffer<Types.MemoryResponse>(10);

    for ((id, record) in memories.entries()) {
      if (record.session_id == session_id) {
        let visible = switch (record.memory_type) {
          case (#Public)    { true                                       };
          case (#Private)   { canBeOwner and caller == record.user_id    };
          case (#Sensitive) { canBeOwner and caller == record.user_id    };
        };
        if (visible) { buf.add(toResponse(id, record)) };
      };
    };

    Array.sort(Buffer.toArray(buf), byTimestampDesc)
  };

  // List recent memories across all users — Public only.
  // Private and Sensitive records are never included in the global listing.
  public query func list_recent_memories(limit : Nat) : async [Types.MemoryResponse] {
    let buf = Buffer.Buffer<Types.MemoryResponse>(100);

    for ((id, record) in memories.entries()) {
      switch (record.memory_type) {
        case (#Public) { buf.add(toResponse(id, record)) };
        case _         { };
      };
    };

    let sorted = Array.sort(Buffer.toArray(buf), byTimestampDesc);
    if (sorted.size() <= limit) sorted
    else Array.tabulate(limit, func(i : Nat) : Types.MemoryResponse { sorted[i] })
  };

  // Health / record count (total, including private)
  public query func health() : async { status : Text; count : Nat } {
    { status = "ok"; count = memories.size() }
  };

  // ─── HTTP gateway ──────────────────────────────────────────────────
  //
  // The HTTP endpoint only serves Public records — the gateway has no
  // authenticated caller context, so private/sensitive records are omitted.
  //
  //   /memory/<user_id>  — public records for that user, newest first
  //   /memory            — health + public record count
  //
  // Accessible at:  https://<canister-id>.ic0.app/memory/<user_id>
  // Locally:        http://localhost:4943/memory/<user_id>?canisterId=<id>
  //
  public query func http_request(req : Types.HttpRequest) : async Types.HttpResponse {
    let urlIter = Text.split(req.url, #char '?');
    let path : Text = switch (urlIter.next()) {
      case (?p) p;
      case null req.url;
    };

    switch (Text.stripStart(path, #text "/memory/")) {
      case (?userId) {
        if (Text.size(userId) == 0) {
          return httpJson(400, "{\"error\":\"Missing user_id\"}");
        };

        let buf = Buffer.Buffer<Types.MemoryResponse>(10);
        for ((id, record) in memories.entries()) {
          if (record.user_id == userId) {
            switch (record.memory_type) {
              case (#Public) { buf.add(toResponse(id, record)) };
              case _         { }; // Private and Sensitive not served over HTTP
            };
          };
        };

        let sorted = Array.sort(Buffer.toArray(buf), byTimestampDesc);
        httpJson(200, jsonArray(sorted))
      };

      case null {
        let publicCount = Iter.size(
          Iter.filter(memories.vals(), func(r : Types.MemoryRecord) : Bool {
            switch (r.memory_type) { case (#Public) true; case _ false }
          })
        );
        if (path == "/memory" or path == "/" or path == "") {
          httpJson(200, "{\"status\":\"ok\",\"public_count\":" # Nat.toText(publicCount) # ",\"total_count\":" # Nat.toText(memories.size()) # "}")
        } else {
          httpJson(404, "{\"error\":\"Not found. Try /memory/<user_id>\"}")
        }
      };
    }
  };

  // ─── Private helpers ───────────────────────────────────────────────

  private func byTimestampDesc(a : Types.MemoryResponse, b : Types.MemoryResponse) : { #less; #equal; #greater } {
    Int.compare(b.timestamp, a.timestamp)
  };

  private func memTypeText(t : Types.MemoryType) : Text {
    switch t {
      case (#Public)    { "public"    };
      case (#Private)   { "private"   };
      case (#Sensitive) { "sensitive" };
    }
  };

  private func toResponse(id : Text, r : Types.MemoryRecord) : Types.MemoryResponse {
    {
      id          = id;
      user_id     = r.user_id;
      session_id  = r.session_id;
      content     = r.content;
      timestamp   = r.timestamp;
      metadata    = r.metadata;
      memory_type = r.memory_type;
    }
  };

  private func httpJson(status : Nat16, body : Text) : Types.HttpResponse {
    {
      status_code        = status;
      headers            = [
        ("Content-Type", "application/json"),
        ("Access-Control-Allow-Origin", "*"),
      ];
      body               = Text.encodeUtf8(body);
      streaming_strategy = null;
      upgrade            = null;
    }
  };

  private func jsonEscape(s : Text) : Text {
    var out = "";
    for (c in Text.toIter(s)) {
      if      (c == '\"') { out #= "\\\"" }
      else if (c == '\\') { out #= "\\\\" }
      else if (c == '\n') { out #= "\\n"  }
      else if (c == '\r') { out #= "\\r"  }
      else if (c == '\t') { out #= "\\t"  }
      else                { out #= Text.fromChar(c) };
    };
    out
  };

  private func jsonRecord(r : Types.MemoryResponse) : Text {
    let meta = switch (r.metadata) {
      case null  { "null" };
      case (?m)  { "\"" # jsonEscape(m) # "\"" };
    };
    "{" #
      "\"id\":\""          # jsonEscape(r.id)              # "\"," #
      "\"user_id\":\""     # jsonEscape(r.user_id)         # "\"," #
      "\"session_id\":\""  # jsonEscape(r.session_id)      # "\"," #
      "\"content\":\""     # jsonEscape(r.content)         # "\"," #
      "\"timestamp\":"     # Int.toText(r.timestamp)       # ","   #
      "\"metadata\":"      # meta                          # ","   #
      "\"memory_type\":\"" # memTypeText(r.memory_type)    # "\""  #
    "}"
  };

  private func jsonArray(records : [Types.MemoryResponse]) : Text {
    var body  = "[";
    var first = true;
    for (r in records.vals()) {
      if (not first) { body #= "," };
      body #= jsonRecord(r);
      first := false;
    };
    body # "]"
  };
};
