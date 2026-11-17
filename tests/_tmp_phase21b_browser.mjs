/**
 * Phase 21B — real browser test against localhost:8080 with logged-in admin.
 * Expects TEMP_PASS already set on user; does not touch DB itself.
 */
import { chromium } from "playwright";
import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BASE = process.env.CRM_BASE || "http://localhost:8080";
const EMAIL = process.env.CRM_EMAIL;
const PASS = process.env.CRM_PASS;
const OUT = process.env.CRM_OUT || path.resolve(__dirname, "../writable/phase21b_screenshots");
fs.mkdirSync(OUT, { recursive: true });

if (!EMAIL || !PASS) {
  console.error("CRM_EMAIL and CRM_PASS required");
  process.exit(1);
}

const consoleErrors = [];
const failedNet = [];
const groupedResponses = [];

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();

page.on("console", (msg) => {
  if (msg.type() === "error") consoleErrors.push(msg.text());
});
page.on("pageerror", (err) => consoleErrors.push(String(err)));
page.on("response", async (res) => {
  const url = res.url();
  const status = res.status();
  const isGrouped = /timesheet_grouped/.test(url);
  const isAsset = /timesheet-grouped\.(js|css)/.test(url);
  if (status >= 400 || isGrouped || isAsset) {
    let body = "";
    try {
      body = await res.text();
    } catch (e) {
      body = "(no body)";
    }
    if (status >= 400) {
      failedNet.push({ url, status, body: body.slice(0, 2000) });
    }
    if (isGrouped) {
      groupedResponses.push({ url, status, body: body.slice(0, 4000) });
    }
    if (isAsset) {
      console.log(`ASSET ${status} ${url}`);
    }
  }
});

async function login() {
  await page.goto(`${BASE}/index.php/signin`, { waitUntil: "domcontentloaded", timeout: 60000 });
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASS);
  await Promise.all([
    page.waitForNavigation({ waitUntil: "domcontentloaded", timeout: 60000 }).catch(() => null),
    page.click('button[type="submit"], input[type="submit"], button:has-text("Sign in"), button:has-text("Login")'),
  ]);
  // Some installs use form submit without clear button text
  if (page.url().includes("signin")) {
    await page.locator('form').first().evaluate((f) => f.submit());
    await page.waitForTimeout(2000);
  }
  const stillLogin = page.url().includes("signin") || (await page.locator('input[name="password"]').count()) > 0;
  console.log(`login_url=${page.url()} still_login=${stillLogin ? 1 : 0}`);
  return !stillLogin;
}

const ok = await login();
if (!ok) {
  await page.screenshot({ path: path.join(OUT, "login_failed.png"), fullPage: true });
  console.log("LOGIN_FAILED");
  console.log(JSON.stringify({ consoleErrors, failedNet }, null, 2));
  await browser.close();
  process.exit(2);
}

// Ensure Details tab
await page.goto(`${BASE}/index.php/projects/all_timesheets`, { waitUntil: "networkidle", timeout: 90000 });
await page.waitForTimeout(1500);
const detailsBtn = page.locator("#timesheet-details-button");
if (await detailsBtn.count()) {
  await detailsBtn.click();
  await page.waitForTimeout(1500);
}

await page.waitForFunction(() => {
  const n = document.querySelectorAll(".tg-project").length;
  const empty = document.querySelector("[data-tg-empty]");
  const emptyShown = empty && empty.offsetParent !== null && getComputedStyle(empty).display !== "none";
  return n > 0 || emptyShown;
}, { timeout: 20000 }).catch(() => null);

await page.screenshot({ path: path.join(OUT, "first_load_1440.png"), fullPage: true });

const projectCount = await page.locator(".tg-project").count();
const emptyVisible = await page.locator("[data-tg-empty]").isVisible().catch(() => false);
const emptyText = emptyVisible ? await page.locator("[data-tg-empty]").innerText() : "";
const hasDatepicker = await page.locator("[name='tg_start_date'][data-convert-date-format]").count();
const tgDefined = await page.evaluate(() => typeof window.TimesheetGrouped);
const listHtmlLen = await page.locator("[data-tg-list]").evaluate((el) => el.innerHTML.length);
const filterDates = await page.evaluate(() => ({
  start: document.querySelector("[name='tg_start_date']")?.value,
  end: document.querySelector("[name='tg_end_date']")?.value,
}));
console.log(`filter_dates=${JSON.stringify(filterDates)}`);

console.log(`projects=${projectCount} empty_visible=${emptyVisible} empty_text=${JSON.stringify(emptyText)}`);
console.log(`datepicker_attr=${hasDatepicker} TimesheetGrouped=${tgDefined} listHtmlLen=${listHtmlLen}`);
console.log(`console_errors=${consoleErrors.length}`);
consoleErrors.forEach((e) => console.log("CONSOLE_ERR " + e));
console.log(`failed_net=${failedNet.length}`);
failedNet.forEach((f) => console.log(`FAIL ${f.status} ${f.url}\n${f.body.slice(0, 300)}`));
console.log(`grouped_responses=${groupedResponses.length}`);
groupedResponses.forEach((g) => console.log(`GROUPED ${g.status} ${g.url}\n${g.body.slice(0, 500)}`));

// 390 first load
await page.setViewportSize({ width: 390, height: 844 });
await page.waitForTimeout(500);
await page.screenshot({ path: path.join(OUT, "first_load_390.png"), fullPage: true });
await page.setViewportSize({ width: 1440, height: 900 });

if (projectCount > 0) {
  const memberSelect = page.locator("[name='tg_user_id']");
  const projectSelect = page.locator("[name='tg_project_id']");
  const clientSelect = page.locator("[name='tg_client_id']");

  // Expand hierarchy on the default first-load data first (before filter churn)
  const firstProjectEarly = page.locator(".tg-project").first();
  await firstProjectEarly.locator("[data-tg-project-toggle]").click();
  await page.waitForSelector(".tg-member", { timeout: 15000 }).catch(() => null);
  await page.waitForTimeout(400);
  await page.screenshot({ path: path.join(OUT, "expanded_project_1440.png"), fullPage: true });
  const firstMemberEarly = page.locator(".tg-member").first();
  if (await firstMemberEarly.count()) {
    await firstMemberEarly.locator("[data-tg-member-toggle]").click();
    await page.waitForSelector(".tg-task", { timeout: 15000 }).catch(() => null);
    await page.waitForTimeout(400);
    await page.screenshot({ path: path.join(OUT, "expanded_member_1440.png"), fullPage: true });
    const entriesBtnEarly = page.locator("[data-tg-entries-toggle]").first();
    if (await entriesBtnEarly.count()) {
      await entriesBtnEarly.click();
      await page.waitForSelector(".tg-entries-table, .tg-entries .tg-empty", { timeout: 10000 }).catch(() => null);
      await page.waitForTimeout(300);
      await page.screenshot({ path: path.join(OUT, "time_entries_open_1440.png"), fullPage: true });
    }
  }

  // Change date range via moment-aware display format
  const converted = await page.evaluate(() => {
    const start = document.querySelector("[name='tg_start_date']");
    const end = document.querySelector("[name='tg_end_date']");
    const sample = start.value || "";
    if (sample.includes("-") && sample.split("-")[0].length === 4) {
      start.value = "2026-10-01";
      end.value = "2026-11-17";
    } else {
      start.value = "01-10-2026";
      end.value = "17-11-2026";
    }
    // Probe what filters() will send
    const probe = window.__tgFiltersProbe;
    return {
      start: start.value,
      end: end.value,
      jsFmt: typeof getJsDateFormat === "function" ? getJsDateFormat() : null,
      appFmt: (window.AppHelper && AppHelper.settings && AppHelper.settings.dateFormat) || null,
    };
  });
  console.log(`date_change_probe=${JSON.stringify(converted)}`);
  const applyResp = page.waitForResponse((r) => /timesheet_grouped_projects/.test(r.url()) && r.request().method() === "POST", { timeout: 20000 });
  await page.locator("[data-tg-apply]").click();
  const ar = await applyResp.catch(() => null);
  if (ar) {
    const body = await ar.text();
    console.log(`after_date_status=${ar.status()} body_start=${body.slice(0, 180)}`);
  }
  await page.waitForTimeout(1000);
  await page.screenshot({ path: path.join(OUT, "after_date_apply_1440.png"), fullPage: true });
  console.log(`after_date_projects=${await page.locator(".tg-project").count()}`);

  // Member filter
  const memberOpts = await memberSelect.locator("option").evaluateAll((opts) =>
    opts.map((o) => ({ v: o.value, t: o.textContent })).filter((o) => o.v)
  );
  if (memberOpts.length) {
    await memberSelect.selectOption(memberOpts[0].v);
    await page.locator("[data-tg-apply]").click();
    await page.waitForTimeout(1500);
    await page.screenshot({ path: path.join(OUT, "filter_member_1440.png"), fullPage: true });
    await memberSelect.selectOption("");
    await page.locator("[data-tg-apply]").click();
    await page.waitForTimeout(1000);
  }

  // Project filter
  const projectOpts = await projectSelect.locator("option").evaluateAll((opts) =>
    opts.map((o) => ({ v: o.value, t: o.textContent })).filter((o) => o.v)
  );
  if (projectOpts.length) {
    await projectSelect.selectOption(projectOpts.find((p) => p.v === "626")?.v || projectOpts[0].v);
    await page.locator("[data-tg-apply]").click();
    await page.waitForTimeout(1500);
    await page.screenshot({ path: path.join(OUT, "filter_project_1440.png"), fullPage: true });
  }

  // Client filter
  if (await clientSelect.count()) {
    const clientOpts = await clientSelect.locator("option").evaluateAll((opts) =>
      opts.map((o) => ({ v: o.value, t: o.textContent })).filter((o) => o.v)
    );
    if (clientOpts.length) {
      await projectSelect.selectOption("");
      await clientSelect.selectOption(clientOpts[0].v);
      await page.locator("[data-tg-apply]").click();
      await page.waitForTimeout(1500);
      await page.screenshot({ path: path.join(OUT, "filter_client_1440.png"), fullPage: true });
      await clientSelect.selectOption("");
    }
  }

  // Clear filters back to default range for Table switch
  await page.evaluate(() => {
    const start = document.querySelector("[name='tg_start_date']");
    const end = document.querySelector("[name='tg_end_date']");
    const sample = start.value || "";
    if (sample.includes("-") && sample.split("-")[0].length === 4) {
      start.value = "2026-10-18";
      end.value = "2026-11-17";
    } else {
      start.value = "18-10-2026";
      end.value = "17-11-2026";
    }
  });
  await projectSelect.selectOption("");
  await memberSelect.selectOption("");
  if (await clientSelect.count()) await clientSelect.selectOption("");
  await page.locator("[data-tg-apply]").click();
  await page.waitForTimeout(1500);

  // Switch Table and back
  await page.locator('[data-tg-mode="table"]').click();
  await page.waitForTimeout(800);
  await page.screenshot({ path: path.join(OUT, "table_mode_1440.png"), fullPage: true });
  await page.locator('[data-tg-mode="grouped"]').click();
  await page.waitForTimeout(800);
  await page.screenshot({ path: path.join(OUT, "grouped_again_1440.png"), fullPage: true });

  await page.setViewportSize({ width: 390, height: 844 });
  await page.waitForTimeout(500);
  await page.screenshot({ path: path.join(OUT, "grouped_390.png"), fullPage: true });
}

const report = {
  projectCount,
  emptyVisible,
  emptyText,
  datepickerAttr: hasDatepicker,
  TimesheetGrouped: tgDefined,
  consoleErrors,
  failedNet,
  groupedResponses: groupedResponses.map((g) => ({
    url: g.url,
    status: g.status,
    bodyPreview: g.body.slice(0, 800),
  })),
  screenshots: fs.readdirSync(OUT).filter((f) => f.endsWith(".png")),
};
fs.writeFileSync(path.join(OUT, "report.json"), JSON.stringify(report, null, 2));
console.log("REPORT " + path.join(OUT, "report.json"));
console.log(projectCount > 0 ? "PASS_HAS_DATA" : emptyVisible ? "PASS_EMPTY_STATE" : "FAIL_BLANK");

await browser.close();
process.exit(projectCount > 0 || emptyVisible ? 0 : 1);
