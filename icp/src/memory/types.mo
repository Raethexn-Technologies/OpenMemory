module {
  public type MemoryRecord = {
    user_id : Text;
    session_id : Text;
    content : Text;
    timestamp : Int;
    metadata : ?Text;
  };

  // user_id is intentionally absent — the canister derives it from msg.caller.
  public type StoreRequest = {
    session_id : Text;
    content    : Text;
    metadata   : ?Text;
  };

  public type MemoryResponse = {
    id : Text;
    user_id : Text;
    session_id : Text;
    content : Text;
    timestamp : Int;
    metadata : ?Text;
  };

  // ─── HTTP gateway types (IC interface spec) ───────────────────────
  public type HeaderField = (Text, Text);

  public type HttpRequest = {
    method  : Text;
    url     : Text;
    headers : [HeaderField];
    body    : Blob;
  };

  public type HttpResponse = {
    status_code        : Nat16;
    headers            : [HeaderField];
    body               : Blob;
    streaming_strategy : ?StreamingStrategy;
    upgrade            : ?Bool;
  };

  // Streaming is never used here; type is required by the IC interface.
  public type StreamingStrategy = {
    #Callback : {
      callback : shared query () -> async ();
      token    : {};
    };
  };
};
