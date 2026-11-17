/**
 * Phase 21C: real Windows Chromium — Abhijna K + Excel download + parse .xlsx
 */
import { chromium } from "playwright";
import fs from "fs";
import path from "path";
import { spawnSync } from "child_process";

const BASE = process.env.CRM_BASE || "http://localhost:8080";
const EMAIL = process.env.CRM_EMAIL;
const PASS = process.env.CRM_PASS;
const OUT = process.env.CRM_OUT || "C:\\Users\\gokul\\AppData\\Local\\Temp\\phase21c_dl";
const FROM = process.env.CRM_FROM || "01-06-2026";
const TO = process.env.CRM_TO || "17-11-2026";
const MEMBER = process.env.CRM_MEMBER || "Abhijna";
const PHP = process.env.PHP_BIN || "C:\\xampp\\php\\php.exe";
const REPO = process.env.CRM_REPO || "\\\\wsl.localhost\\Ubuntu\\home\\gokul\\projects\\crm_sunrise";

if (!EMAIL || !PASS) {
  console.error("CRM_EMAIL and CRM_PASS required");
  process.exit(1);
}

fs.mkdirSync(OUT, { recursive: true });

async function main() {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ acceptDownloads: true });
  const page = await context.newPage();

  await page.goto(`${BASE}/index.php/signin`, { waitUntil: "domcontentloaded", timeout: 60000 });
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASS);
  await Promise.all([
    page.waitForNavigation({ waitUntil: "domcontentloaded", timeout: 60000 }).catch(() => null),
    page.click('button[type="submit"], input[type="submit"], button:has-text("Sign in"), button:has-text("Login")'),
  ]);
  console.log("login_url=" + page.url());

  await page.goto(`${BASE}/index.php/projects/all_timesheets`, { waitUntil: "networkidle", timeout: 90000 });
  await page.waitForTimeout(1500);
  const detailsBtn = page.locator("#timesheet-details-button");
  if (await detailsBtn.count()) {
    await detailsBtn.click();
    await page.waitForTimeout(1000);
  }
  await page.waitForSelector("#timesheet-grouped-root", { timeout: 60000 });

  // Set datepicker fields directly (fill alone may not update datepicker internals)
  await page.evaluate(
    ({ from, to }) => {
      const s = document.querySelector("[name='tg_start_date']");
      const e = document.querySelector("[name='tg_end_date']");
      if (s) {
        s.value = from;
        s.dispatchEvent(new Event("change", { bubbles: true }));
      }
      if (e) {
        e.value = to;
        e.dispatchEvent(new Event("change", { bubbles: true }));
      }
    },
    { from: FROM, to: TO }
  );

  const filterDates = await page.evaluate(() => ({
    start: document.querySelector("[name='tg_start_date']")?.value,
    end: document.querySelector("[name='tg_end_date']")?.value,
  }));
  console.log("filter_dates=" + JSON.stringify(filterDates));

  const memberSelect = page.locator("[name='tg_user_id']");
  await memberSelect.waitFor({ state: "visible", timeout: 30000 });
  const optionValues = await memberSelect.evaluate((sel) =>
    Array.from(sel.options).map((o) => ({ value: o.value, text: o.textContent.trim() }))
  );
  const hit = optionValues.find((o) => o.text.includes(MEMBER));
  if (!hit) {
    console.error("member_options=" + JSON.stringify(optionValues.slice(0, 30)));
    throw new Error("Abhijna option not found");
  }
  await memberSelect.selectOption(hit.value);
  console.log("selected_member=" + hit.text + " value=" + hit.value);

  await page.click("[data-tg-apply]");
  await page.waitForFunction(() => {
    const n = document.querySelectorAll(".tg-project").length;
    const empty = document.querySelector("[data-tg-empty]");
    const emptyShown = empty && empty.offsetParent !== null && getComputedStyle(empty).display !== "none";
    return n > 0 || emptyShown;
  }, { timeout: 60000 }).catch(() => null);
  await page.waitForTimeout(1500);

  const projects = await page.locator(".tg-project").count();
  console.log("screen_projects=" + projects);

  const [download] = await Promise.all([
    page.waitForEvent("download", { timeout: 180000 }),
    page.click('[data-tg-export="xlsx"]'),
  ]);
  const suggested = download.suggestedFilename();
  const dest = path.join(OUT, suggested || "timesheets_abhijna.xlsx");
  await download.saveAs(dest);
  const stat = fs.statSync(dest);
  console.log("downloaded=" + dest + " bytes=" + stat.size + " name=" + suggested);

  const buf = Buffer.alloc(2);
  const fd = fs.openSync(dest, "r");
  fs.readSync(fd, buf, 0, 2, 0);
  fs.closeSync(fd);
  const isZip = buf[0] === 0x50 && buf[1] === 0x4b;
  console.log("is_xlsx_zip=" + (isZip ? 1 : 0));

  const parsePhp = path.join(OUT, "_parse_xlsx.php");
  const autoload = path.join(REPO, "app\\ThirdParty\\PHPOffice-PhpSpreadsheet\\vendor\\autoload.php");
  fs.writeFileSync(
    parsePhp,
    `<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require ${JSON.stringify(autoload)};
$file = ${JSON.stringify(dest)};
$sh = PhpOffice\\PhpSpreadsheet\\IOFactory::load($file)->getActiveSheet();
$title = (string)$sh->getCell('A1')->getValue();
$last = $sh->getHighestRow();
$total = '';
$labels = 0;
for ($i = 1; $i <= $last; $i++) {
  if (strcasecmp((string)$sh->getCellByColumnAndRow(1, $i)->getValue(), 'Total') === 0) {
    $labels++;
    $total = (string)$sh->getCellByColumnAndRow(7, $i)->getValue();
  }
}
echo json_encode([
  'title' => $title,
  'total' => $total,
  'last' => $last,
  'data_rows' => max(0, $last - 3),
  'total_labels' => $labels,
]);
`
  );
  const parsed = spawnSync(PHP, [parsePhp], { encoding: "utf8", timeout: 120000 });
  const outLines = (parsed.stdout || "").trim().split(/\r?\n/).filter(Boolean);
  const jsonLine = outLines.reverse().find((l) => l.startsWith("{")) || "";
  console.log("parse_json=" + jsonLine);
  let info = {};
  try {
    info = JSON.parse(jsonLine);
  } catch (_) {}
  const titleOk =
    !!info.title &&
    /abhijna/i.test(info.title) &&
    /01-06-2026/.test(info.title) &&
    /17-11-2026/.test(info.title);
  console.log("BROWSER_TITLE_OK=" + (titleOk ? 1 : 0));
  console.log("BROWSER_TOTAL_LABELS=" + (info.total_labels || 0));
  console.log("BROWSER_DATA_ROWS=" + (info.data_rows || 0));
  console.log("BROWSER_TOTAL=" + (info.total || ""));
  console.log("BROWSER_TITLE=" + (info.title || ""));

  await browser.close();
  if (!isZip || !titleOk || (info.total_labels || 0) !== 1 || (info.data_rows || 0) < 1) {
    console.log("BROWSER_PROOF=FAIL");
    process.exit(2);
  }
  console.log("BROWSER_PROOF=PASS");
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
