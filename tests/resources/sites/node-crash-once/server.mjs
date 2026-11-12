import { existsSync, writeFileSync } from "node:fs";
import { createServer } from "node:http";

// The container keeps this file when it restarts, so only the first start crashes
const marker = "/usr/local/server/crashed";
if (!existsSync(marker)) {
  writeFileSync(marker, "");
  // Crashing after 3 s keeps the restart clear of the first crash check, made 2 s after the runtime is created
  setTimeout(() => {
    throw new Error("Crashing on the first start");
  }, 3000);
} else {
  // Listening late leaves the restarted container unanswered through at least one crash check
  setTimeout(() => createServer((req, res) => res.end("OK")).listen(3000), 5000);
}
