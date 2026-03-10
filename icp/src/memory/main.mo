import HashMap "mo:base/HashMap";
import Text "mo:base/Text";
import Time "mo:base/Time";
import Nat "mo:base/Nat";
import Int "mo:base/Int";
import Iter "mo:base/Iter";
import Buffer "mo:base/Buffer";
import Array "mo:base/Array";
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

  // Store a memory record
  public func store_memory(req : Types.StoreRequest) : async Text {
    let id = req.user_id # ":" # Nat.toText(nextId);
    nextId += 1;

    let record : Types.MemoryRecord = {
      user_id    = req.user_id;
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
};
