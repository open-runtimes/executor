import { existsSync, writeFileSync } from "node:fs";
import type { EntryContext } from "@remix-run/node";
import { RemixServer } from "@remix-run/react";
import { renderToString } from "react-dom/server";

// Kept across restarts, so only the first start crashes
const marker = "/usr/local/server/crashed";
if (!existsSync(marker)) {
  writeFileSync(marker, "");
  // Lands after the executor's first crash check at 2 s
  setTimeout(() => {
    throw new Error("Crashing on the first start");
  }, 3000);
}

// The restarted server listens late, past at least one crash check
await new Promise((resolve) => setTimeout(resolve, 5000));

export default function handleRequest(
  request: Request,
  responseStatusCode: number,
  responseHeaders: Headers,
  remixContext: EntryContext,
) {
  const html = renderToString(
    <RemixServer context={remixContext} url={request.url} />,
  );
  responseHeaders.set("Content-Type", "text/html");

  return new Response("<!DOCTYPE html>" + html, {
    status: responseStatusCode,
    headers: responseHeaders,
  });
}
