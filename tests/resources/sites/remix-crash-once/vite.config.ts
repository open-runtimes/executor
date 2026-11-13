import { vitePlugin as remix } from "@remix-run/dev";
import { defineConfig } from "vite";

export default defineConfig({
  // app/entry.server.tsx waits with a top-level await
  build: { target: "esnext" },
  plugins: [remix()],
});
