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

  // Store a memory record.
  // user_id is always the authenticated caller — the request body cannot override it.
  // In ICP live mode this is cryptographically enforced: msg.caller is derived from
  // the browser-generated Ed25519 key (or Internet Identity), never trusted from
  // an unverified field in the request body.
  public shared(msg) func store_memory(req : Types.StoreRequest) : async Text {
    let caller = Principal.toText(msg.caller);
    let id     = caller # ":" # Nat.toText(nextId);
    nextId += 1;

    let record : Types.MemoryRecord = {
      user_id    = caller;
      session_id = req.session_id;
      content    = req.content;
      timestamp  = Time.now();
      metadata   = req.metadata;
    };

    memories.put(id, record);
    id
  };

  // Get all memories for a user, newest first
  public query func get_memories(user_id : Text) : async [Types.MemoryResponse] {
    let buf = Buffer.Buffer<Types.MemoryResponse>(10);

    for ((id, record) in memories.entries()) {
      if (record.user_id == user_id) {
        buf.add(toResponse(id, record));
      };
    };

    let arr = Buffer.toArray(buf);
    Array.sort(arr, func(a : Types.MemoryResponse, b : Types.MemoryResponse) : { #less; #equal; #greater } {
      Int.compare(b.timestamp, a.timestamp)
    })
  };

  // Get memories for a specific session, newest first
  public query func get_memories_by_session(session_id : Text) : async [Types.MemoryResponse] {
    let buf = Buffer.Buffer<Types.MemoryResponse>(10);

    for ((id, record) in memories.entries()) {
      if (record.session_id == session_id) {
        buf.add(toResponse(id, record));
      };
    };

    let arr = Buffer.toArray(buf);
    Array.sort(arr, func(a : Types.MemoryResponse, b : Types.MemoryResponse) : { #less; #equal; #greater } {
      Int.compare(b.timestamp, a.timestamp)
    })
  };

  // List the most recent N memories across all users, newest first
  public query func list_recent_memories(limit : Nat) : async [Types.MemoryResponse] {
    let buf = Buffer.Buffer<Types.MemoryResponse>(100);

    for ((id, record) in memories.entries()) {
      buf.add(toResponse(id, record));
    };

    // Sort all by timestamp descending, then take limit
    let sorted = Array.sort(Buffer.toArray(buf), func(a : Types.MemoryResponse, b : Types.MemoryResponse) : { #less; #equal; #greater } {
      Int.compare(b.timestamp, a.timestamp)
    });

    if (sorted.size() <= limit) sorted
    else Array.tabulate(limit, func(i : Nat) : Types.MemoryResponse { sorted[i] })
  };

  // Delete a specific memory record
  public func delete_memory(id : Text) : async Bool {
    switch (memories.remove(id)) {
      case (?_) true;
      case null  false;
    }
  };

  // Health / record count
  public query func health() : async { status : Text; count : Nat } {
    { status = "ok"; count = memories.size() }
  };

  // ─── HTTP gateway ──────────────────────────────────────────────────
  //
  // Serves memory as JSON at:
  //   /memory/<user_id>  — records for that user, newest first
  //   /memory            — health + record count
  //   /                  — same as /memory
  //
  // Accessible at:  https://<canister-id>.ic0.app/memory/<user_id>
  // Locally:        http://localhost:4943/?canisterId=<id>&path=/memory/<user_id>
  //
  public query func http_request(req : Types.HttpRequest) : async Types.HttpResponse {
    // Strip query string from URL so routing works regardless of params.
    let urlIter = Text.split(req.url, #char '?');
    let path : Text = switch (urlIter.next()) {
      case (?p) p;
      case null req.url;
    };

    // Route: /memory/<user_id>
    switch (Text.stripStart(path, #text "/memory/")) {
      case (?userId) {
        if (Text.size(userId) == 0) {
          return httpJson(400, "{\"error\":\"Missing user_id\"}");
        };

        let buf = Buffer.Buffer<Types.MemoryResponse>(10);
        for ((id, record) in memories.entries()) {
          if (record.user_id == userId) {
            buf.add(toResponse(id, record));
          };
        };

        let sorted = Array.sort(
          Buffer.toArray(buf),
          func(a : Types.MemoryResponse, b : Types.MemoryResponse) : { #less; #equal; #greater } {
            Int.compare(b.timestamp, a.timestamp)
          }
        );

        httpJson(200, jsonArray(sorted))
      };

      // Route: /memory  or  /
      case null {
        if (path == "/memory" or path == "/" or path == "") {
          httpJson(200, "{\"status\":\"ok\",\"count\":" # Nat.toText(memories.size()) # "}")
        } else {
          httpJson(404, "{\"error\":\"Not found. Try /memory/<user_id>\"}")
        }
      };
    }
  };

  // ─── Private helpers ───────────────────────────────────────────────
  private func toResponse(id : Text, r : Types.MemoryRecord) : Types.MemoryResponse {
    {
      id         = id;
      user_id    = r.user_id;
      session_id = r.session_id;
      content    = r.content;
      timestamp  = r.timestamp;
      metadata   = r.metadata;
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
      case null    { "null" };
      case (?m)    { "\"" # jsonEscape(m) # "\"" };
    };
    "{" #
      "\"id\":\""         # jsonEscape(r.id)         # "\"," #
      "\"user_id\":\""    # jsonEscape(r.user_id)    # "\"," #
      "\"session_id\":\"" # jsonEscape(r.session_id) # "\"," #
      "\"content\":\""    # jsonEscape(r.content)    # "\"," #
      "\"timestamp\":"    # Int.toText(r.timestamp)  # ","   #
      "\"metadata\":"     # meta                              #
    "}"
  };

  private func jsonArray(records : [Types.MemoryResponse]) : Text {
    var body = "[";
    var first = true;
    for (r in records.vals()) {
      if (not first) { body #= "," };
      body #= jsonRecord(r);
      first := false;
    };
    body # "]"
  };
};
