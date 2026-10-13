# Claim Done MCP server

**Australian legal documents, drafted from a person's answers for a flat fee, from inside any AI assistant.**

Claim Done ([claimdone.com.au](https://claimdone.com.au)) drafts letters of demand, tribunal applications (NCAT, VCAT, QCAT, SACAT, ACAT, NTCAT and the Magistrates Courts), cease and desist letters, notices and agreements for people and small businesses in every Australian state and territory. This repository documents the public [Model Context Protocol](https://modelcontextprotocol.io) server that lets an assistant run the whole job: match the problem to a document, ask the questions, hand the person a Stripe payment link, and bring the draft back.

Claim Done is not a law firm and does not give legal advice. The person reads and approves every document on their dashboard before anything is sent.

## Connect

| Client | How |
|---|---|
| Claude (claude.ai, desktop) | Settings → Connectors → Add custom connector → URL `https://claimdone.com.au/mcp`, no OAuth |
| ChatGPT | Settings → Connectors → Create (developer mode) → server URL `https://claimdone.com.au/mcp`, no authentication |
| Claude Code | `claude mcp add --transport http claimdone https://claimdone.com.au/mcp` |
| Cursor and others | `{ "mcpServers": { "claimdone": { "url": "https://claimdone.com.au/mcp" } } }` |

- Transport: Streamable HTTP, stateless, JSON-RPC 2.0, protocol version `2025-06-18`
- Authentication: none (each case is protected by its own access token returned by `start_document`)
- Description file: [`/.well-known/mcp.json`](https://claimdone.com.au/.well-known/mcp.json)
- Set-up page for people: [claimdone.com.au/connect-your-ai-assistant](https://claimdone.com.au/connect-your-ai-assistant/)

## Tools

| Tool | What it does | Read-only |
|---|---|---|
| `list_services` | Every document with its live flat price in AUD, what the person receives and how it is delivered. Optional `query` filter. | yes |
| `match_problem` | A plain-English description of the situation → the document that fits, its price and what it does. | yes |
| `start_document` | Creates the case (`service`, `state`, `what_happened`, `your_name`, `your_email`) and returns the questions plus `case_id` and `access_token`. Nothing is charged. | no |
| `answer_questions` | Saves answers (`key` → answer). Returns remaining required questions, follow-up questions the drafting needs, or `ready_to_pay: true`. | no |
| `get_payment_link` | A hosted Stripe Checkout URL at the live price. The person pays there; the document is drafted automatically after payment. Eligibility notices must be acknowledged first. | no |
| `get_status` | Paid? Draft ready? Dashboard link. | yes |
| `get_document` | After payment: the full text of each drafted document and the dashboard link where the person approves it. The server never sends a document on anyone's behalf. | yes |

A typical run: `match_problem` → `start_document` → `answer_questions` (repeat until `ready_to_pay`) → `get_payment_link` → `get_status` → `get_document`.

## Example (Python, official `mcp` client)

```python
import asyncio, json
from mcp import ClientSession
from mcp.client.streamable_http import streamable_http_client

async def main():
    async with streamable_http_client("https://claimdone.com.au/mcp") as streams:
        async with ClientSession(streams[0], streams[1]) as s:
            await s.initialize()
            r = await s.call_tool("match_problem", {"problem": "A customer has not paid my $2,400 invoice for fencing work and ignores my emails"})
            print(json.loads(r.content[0].text)["recommended"])

asyncio.run(main())
```

## Example (raw JSON-RPC)

```bash
curl -s -X POST https://claimdone.com.au/mcp -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

## Rules the server enforces

- Rate limits per address: 120 calls a minute, 15 new documents an hour.
- Prices are read live; the price quoted when checkout opens is the price charged.
- One open checkout per case; an already-paid case is never charged twice.
- Answers that mean Claim Done cannot prepare the document (for example a contact order against the other party) stop the case before payment.
- Delivery follows the document: letters are emailed to the other party only after the person approves them; applications and agreements come to the person as a PDF to lodge or send.

## About

The server is part of the Claim Done platform (WordPress plugin, PHP). The implementation wraps the same intake, follow-up and payment code the website uses, so every rule applies identically. Support: support@claimdone.com.au. Privacy policy: https://claimdone.com.au/privacy/. Terms: https://claimdone.com.au/terms/.
