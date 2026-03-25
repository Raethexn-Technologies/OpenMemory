/**
 * BaseConnector — abstract base class for transport connectors.
 *
 * Each connector extends this class and implements start() and optionally
 * stop(). The constructor receives the Agent instance so the connector can
 * call this.agent.handleMessage(...) when an authorized message arrives.
 */

export class BaseConnector {
  constructor(agent) {
    this.agent = agent;
  }

  /**
   * Connect to the external platform and begin processing messages.
   * Must be implemented by every subclass.
   *
   * @returns {Promise<void>}
   */
  async start() {
    throw new Error(`${this.constructor.name} must implement start()`);
  }

  /**
   * Disconnect from the platform gracefully.
   * Subclasses should override this if the platform client requires cleanup.
   *
   * @returns {Promise<void>}
   */
  async stop() {}
}
