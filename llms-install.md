# Installing the Claim Done MCP server

Claim Done is a hosted (remote) MCP server. There is nothing to download, build or run locally and no API key is needed.

- Endpoint: `https://claimdone.com.au/mcp`
- Transport: Streamable HTTP (stateless JSON-RPC 2.0)
- Authentication: none

## Cline

Add this entry to Cline's MCP settings file (`cline_mcp_settings.json`, reachable from the MCP Servers panel → Configure):

```json
{
  "mcpServers": {
    "claimdone": {
      "type": "streamableHttp",
      "url": "https://claimdone.com.au/mcp",
      "disabled": false,
      "autoApprove": []
    }
  }
}
```

Or in the MCP Servers panel choose **Remote Servers**, enter the name `claimdone` and the URL `https://claimdone.com.au/mcp`, and click Add.

## Verify

Ask the assistant to call `list_services`. It should return the current list of Australian legal documents with their prices in AUD. The full flow is `match_problem` → `start_document` → `answer_questions` (until `ready_to_pay`) → `get_payment_link` → `get_status` → `get_document`. Nothing is charged until the person pays on the Stripe page themselves.

## Other clients

- Claude Code: `claude mcp add --transport http claimdone https://claimdone.com.au/mcp`
- Cursor, Windsurf: `{ "mcpServers": { "claimdone": { "url": "https://claimdone.com.au/mcp" } } }`
- VS Code: `{ "servers": { "claimdone": { "type": "http", "url": "https://claimdone.com.au/mcp" } } }`
- Claude.ai and ChatGPT: add a custom connector with the URL above (no OAuth).

Claim Done is not a law firm and does not give legal advice. Documents are drafted from the person's answers and approved by them before anything is sent.
