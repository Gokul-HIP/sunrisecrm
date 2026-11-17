import { chromium } from "playwright";
import path from "path";
import { fileURLToPath, pathToFileURL } from "url";
import fs from "fs";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, "..");
const outDir = path.join(root, "writable", "phase21_screenshots");
const fixture = path.join(outDir, "fixture.html");
fs.mkdirSync(outDir, { recursive: true });

const widths = [1440, 1024, 768, 390];
const states = ["collapsed", "project", "member", "entries"];

const browser = await chromium.launch({ headless: true });
for (const state of states) {
  for (const w of widths) {
    const page = await browser.newPage({ viewport: { width: w, height: 900 } });
    const url = pathToFileURL(fixture).href + "?state=" + state;
    await page.goto(url, { waitUntil: "load" });
    const file = path.join(outDir, `tg_${state}_${w}.png`);
    await page.screenshot({ path: file, fullPage: true });
    console.log(file);
    await page.close();
  }
}
await browser.close();
console.log("DONE");
