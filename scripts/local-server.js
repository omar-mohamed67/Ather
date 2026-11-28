import path from "node:path";
import fs from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { loadConfig } from "../server/config.js";
import { openDatabase, migrate } from "../server/db/index.js";
import { importLegacy } from "../server/services/legacy-import.js";
import { createApp } from "../server/app.js";
import { LoginThrottle } from "../server/services/login-throttle.js";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const config = loadConfig({
  ...process.env,
  NODE_ENV: "development",
  DB_DRIVER: "pglite",
  PGLITE_PATH: path.resolve(root, process.env.ATHAR_LOCAL_DB_PATH || "data/athar-local-recovered"),
  IMPORT_LEGACY_ON_START: "true",
  PUBLIC_URL: "",
  COOKIE_SECURE: "false",
  SESSION_COOKIE_NAME: "athar_local_sid",
});
await fs.mkdir(path.dirname(config.embeddedPath), { recursive: true });
const db = await openDatabase(config);
try {
  await migrate(db, config.root);
  if (config.importLegacy) {
    const result = await importLegacy(db, config.legacyDir);
    if (result.users) console.log(`Imported ${result.users} saved accounts.`);
  }
} catch (error) {
  await db.close();
  throw error;
}
const backupPath = `${config.embeddedPath}.backup.tgz`;
const previousBackupPath = `${config.embeddedPath}.backup.previous.tgz`;
let backupInProgress = null;
function saveLocalBackup() {
  if (backupInProgress) return backupInProgress;
  backupInProgress = (async () => {
    const archive = await db.backupDataDir();
    const temporaryPath = `${backupPath}.tmp`;
    await fs.writeFile(temporaryPath, Buffer.from(await archive.arrayBuffer()));
    try {
      await fs.copyFile(backupPath, previousBackupPath);
    } catch (error) {
      if (error.code !== "ENOENT") throw error;
    }
    await fs.rename(temporaryPath, backupPath);
  })().finally(() => {
    backupInProgress = null;
  });
  return backupInProgress;
}
await saveLocalBackup().catch((error) =>
  console.error(`Local backup could not be saved: ${error.message}`),
);
const server = createApp({ db, config });
const cleanup = setInterval(() =>
  db.query("DELETE FROM sessions WHERE expires_at < now()")
    .then(() => new LoginThrottle(db).cleanup())
    .catch(() => console.error("Local security cleanup failed.")),
3600000);
cleanup.unref();
const backupTimer = setInterval(() =>
  saveLocalBackup().catch((error) =>
    console.error(`Local backup could not be saved: ${error.message}`),
  ),
900000);
backupTimer.unref();
server.listen(config.port, config.host, () => {
  const port = server.address().port;
  console.log(`Athar is running with a saved local database on port ${port}.`);
  if (process.send) process.send({ type: "athar-ready", port });
});
let closing = false;
async function shutdown() {
  if (closing) return;
  closing = true;
  clearInterval(cleanup);
  clearInterval(backupTimer);
  server.close(async () => {
    try {
      await saveLocalBackup();
    } catch (error) {
      console.error(`Local backup could not be saved: ${error.message}`);
    } finally {
      await db.close();
      process.exit(0);
    }
  });
}
process.on("SIGINT", shutdown);
process.on("SIGTERM", shutdown);
