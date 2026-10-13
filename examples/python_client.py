"""Drive the Claim Done MCP server from Python with the official `mcp` client (pip install mcp).

Runs: match the problem -> start a letter of demand -> answer the questions -> get the payment link.
Uses example values; nothing is paid unless you open the Stripe link.
"""
import asyncio
import json

from mcp import ClientSession
from mcp.client.streamable_http import streamable_http_client

URL = "https://claimdone.com.au/mcp"


def value(result):
    return getattr(result, "structured_content", None) or json.loads(result.content[0].text)


async def main():
    async with streamable_http_client(URL) as streams:
        async with ClientSession(streams[0], streams[1]) as s:
            await s.initialize()

            match = value(await s.call_tool("match_problem", {
                "problem": "A customer, Example Pty Ltd, has not paid my $2,400 invoice for fencing work and ignores my emails"}))
            print("Recommended:", match["recommended"]["name"], "$", match["recommended"]["price_aud"])

            start = value(await s.call_tool("start_document", {
                "service": match["recommended"]["service"],
                "state": "NSW",
                "what_happened": "Example Pty Ltd has not paid invoice 1042 for $2,400 of fencing work finished on 2 September 2026. Two reminders were ignored.",
                "your_name": "Example Fencing",
                "your_email": "you@example.com",
            }))
            case_id, token = start["case_id"], start["access_token"]
            for q in start["questions"]:
                print("Q:", q["key"], "-", q["question"], "(required)" if q["required"] else "(optional)")

            answers = {"lod_claim_type": "Unpaid invoice or work I did", "recipient_name": "Example Pty Ltd", "lod_amount": "2400"}
            while True:
                r = value(await s.call_tool("answer_questions", {"case_id": case_id, "access_token": token, "answers": answers}))
                if r.get("ready_to_pay"):
                    break
                follow = r.get("follow_up_questions") or r.get("remaining_questions") or []
                if not follow:
                    print("Stopped:", r)
                    return
                # In a real assistant you would ask the person each of these.
                answers = {q["key"]: "Invoice 1042, sent 2 September 2026, due 16 September 2026; 12 Example Street, Sydney NSW 2000; 14 days to pay." for q in follow}

            pay = value(await s.call_tool("get_payment_link", {"case_id": case_id, "access_token": token}))
            print("Pay $", pay["amount_aud"], "at", pay["checkout_url"])


asyncio.run(main())
