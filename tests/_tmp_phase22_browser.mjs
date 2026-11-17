/**
 * Phase 22: Windows Chromium — Member→Project hierarchy screenshots + console/network check.
 */
import { chromium } from "playwright";
import fs from "fs";
import path from "path";

const BASE = process.env.CRM_BASE || "http://localhost:8080";
const EMAIL = process.env.CRM_EMAIL;
const PASS = process.env.CRM_PASS;
const OUT = process.env.CRM_OUT || path.join(
  "\\\\wsl.localhost\\Ubuntu\\home\\gokul\\projects\\crm_sunrise",
  "writable",
  "phase22_screenshots"
);
const FROM = process.env.CRM_FROM || "01-10-2026";
const TO = process.env.CRM_TO || "17-11-2026";

if (!EMAIL || !PASS) {
  console.error("CRM_EMAIL and CRM_PASS required");
  process.exit(1);
}

fs.mkdirSync(OUT, { recursive: true });

const consoleErrors = [];
const failedRequests = [];

async function shot(page, name) {
  const dest = path.join(OUT, name);
  await page.screenshot({ path: dest, fullPage: true });
  console.log("shot=" + dest);
  return dest;
}

async function setDates(page, from, to) {
  await page.evaluate(
    ({ from, to }) => {
      const s = document.querySelector("[name='tg_start_date']");
      const e = document.querySelector("[name='tg_end_date']");
      if (s) {
        s.value = from;
        s.dispatchEvent(new Event("change", { bubbles: true }));
        s.dispatchEvent(new Event("input", { bubbles: true }));
      }
      if (e) {
        e.value = to;
        e.dispatchEvent(new Event("change", { bubbles: true }));
        e.dispatchEvent(new Event("input", { bubbles: true }));
      }
    },
    { from, to }
  );
}

async function waitMembers(page) {
  await page.waitForFunction(() => {
    const n = document.querySelectorAll(".tg-member-root").length;
    const empty = document.querySelector("[data-tg-empty]");
    const emptyShown = empty && empty.offsetParent !== null && getComputedStyle(empty).display !== "none";
    const loading = document.querySelector("[data-tg-loading]");
    const loadingShown = loading && loading.offsetParent !== null && getComputedStyle(loading).display !== "none";
    return (n > 0 || emptyShown) && !loadingShown;
  }, { timeout: 90000 });
}

async function main() {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();

  page.on("console", (msg) => {
    if (msg.type() === "error") consoleErrors.push(msg.text());
  });
  page.on("pageerror", (err) => consoleErrors.push(String(err)));
  page.on("requestfailed", (req) => {
    failedRequests.push(req.method() + " " + req.url() + " " + (req.failure()?.errorText || ""));
  });
  page.on("response", (res) => {
    if (res.status() >= 400) {
      failedRequests.push(res.status() + " " + res.request().method() + " " + res.url());
    }
  });

  await page.goto(`${BASE}/index.php/signin`, { waitUntil: "domcontentloaded", timeout: 60000 });
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASS);
  await Promise.all([
    page.waitForNavigation({ waitUntil: "domcontentloaded", timeout: 60000 }).catch(() => null),
    page.click('button[type="submit"], input[type="submit"], button:has-text("Sign in"), button:has-text("Login")'),
  ]);
  if (page.url().includes("signin")) {
    await page.locator("form").first().evaluate((f) => f.submit());
    await page.waitForTimeout(2000);
  }
  console.log("login_url=" + page.url());
  if (page.url().includes("signin")) {
    await shot(page, "login_failed.png");
    throw new Error("LOGIN_FAILED");
  }

  await page.goto(`${BASE}/index.php/projects/all_timesheets`, { waitUntil: "networkidle", timeout: 90000 });
  await page.waitForTimeout(1200);
  const detailsBtn = page.locator("#timesheet-details-button");
  if (await detailsBtn.count()) {
    await detailsBtn.click();
    await page.waitForTimeout(800);
  }
  // Grouped pane may need Details tab active
  const groupedTab = page.locator("[data-tg-mode='grouped'], #timesheet-details-button, a[href='#timesheet-details']");
  if (await page.locator("#timesheet-grouped-root").count() === 0) {
    await page.locator("a:has-text('Details'), button:has-text('Details')").first().click().catch(() => null);
    await page.waitForTimeout(1000);
  }
  await page.waitForSelector("#timesheet-grouped-root", { timeout: 60000 });

  await setDates(page, FROM, TO);
  await page.click("[data-tg-apply]");
  await waitMembers(page);
  await page.waitForTimeout(500);
  await shot(page, "01_first_load_1440.png");

  const memberCount = await page.locator(".tg-member-root").count();
  console.log("members=" + memberCount);

  // Open first real member (skip Unassigned if first)
  let memberIdx = 0;
  const memberIds = await page.$$eval(".tg-member-root", (els) =>
    els.map((el) => el.getAttribute("data-member-id"))
  );
  for (let i = 0; i < memberIds.length; i++) {
    if (String(memberIds[i]) !== "0" && String(memberIds[i]) !== "") {
      memberIdx = i;
      break;
    }
  }
  const member = page.locator(".tg-member-root").nth(memberIdx);
  await member.locator("[data-tg-member-toggle]").click();
  await page.waitForSelector(".tg-member-root.is-open .tg-project-nested, .tg-member-root.is-open .tg-project", {
    timeout: 60000,
  });
  await page.waitForTimeout(600);
  await shot(page, "02_open_member_1440.png");

  const project = member.locator(".tg-project, .tg-project-nested").first();
  await project.locator("[data-tg-project-toggle]").click();
  await page.waitForSelector(".tg-task", { timeout: 60000 });
  await page.waitForTimeout(600);
  await shot(page, "03_open_project_1440.png");

  const entriesBtn = page.locator("[data-tg-entries-toggle]").first();
  if (await entriesBtn.count()) {
    await entriesBtn.click();
    await page.waitForTimeout(1200);
  }
  await shot(page, "04_open_time_entries_1440.png");

  // Member filter
  const memberSelect = page.locator("[name='tg_user_id']");
  await memberSelect.waitFor({ state: "visible", timeout: 30000 });
  const options = await memberSelect.evaluate((sel) =>
    Array.from(sel.options).map((o) => ({ value: o.value, text: o.textContent.trim() }))
  );
  const realMember = options.find((o) => o.value && o.value !== "0" && !/all/i.test(o.text));
  if (realMember) {
    await memberSelect.selectOption(realMember.value);
    await page.click("[data-tg-apply]");
    await waitMembers(page);
    await page.waitForTimeout(400);
    await shot(page, "05_filter_member_1440.png");
    console.log("filter_member=" + realMember.text);
  }

  // Reset member, apply project filter
  await memberSelect.selectOption("");
  const projectSelect = page.locator("[name='tg_project_id']");
  const projOpts = await projectSelect.evaluate((sel) =>
    Array.from(sel.options).map((o) => ({ value: o.value, text: o.textContent.trim() }))
  );
  const realProj = projOpts.find((o) => o.value && o.value !== "0" && !/all/i.test(o.text));
  if (realProj) {
    await projectSelect.selectOption(realProj.value);
    await page.click("[data-tg-apply]");
    await waitMembers(page);
    await page.waitForTimeout(400);
    await shot(page, "06_filter_project_1440.png");
    console.log("filter_project=" + realProj.text);
  }

  // Client filter
  await projectSelect.selectOption("");
  const clientSelect = page.locator("[name='tg_client_id']");
  if (await clientSelect.count()) {
    const clientOpts = await clientSelect.evaluate((sel) =>
      Array.from(sel.options).map((o) => ({ value: o.value, text: o.textContent.trim() }))
    );
    const realClient = clientOpts.find((o) => o.value && o.value !== "0" && !/all/i.test(o.text));
    if (realClient) {
      await clientSelect.selectOption(realClient.value);
      await page.click("[data-tg-apply]");
      await waitMembers(page);
      await page.waitForTimeout(400);
      await shot(page, "07_filter_client_1440.png");
      console.log("filter_client=" + realClient.text);
    }
  }

  // Date change (narrower)
  await setDates(page, "01-11-2026", "17-11-2026");
  await page.click("[data-tg-apply]");
  await waitMembers(page);
  await page.waitForTimeout(400);
  await shot(page, "08_date_change_1440.png");

  // Table <-> Grouped
  await page.click("[data-tg-mode='table']");
  await page.waitForTimeout(1500);
  await shot(page, "09_table_view_1440.png");
  await page.click("[data-tg-mode='grouped']");
  await waitMembers(page);
  await page.waitForTimeout(400);
  await shot(page, "10_grouped_again_1440.png");

  // 390 px
  await page.setViewportSize({ width: 390, height: 844 });
  await page.waitForTimeout(500);
  await shot(page, "11_viewport_390.png");

  console.log("console_errors=" + JSON.stringify(consoleErrors));
  console.log("failed_requests=" + JSON.stringify(failedRequests));
  console.log("console_error_count=" + consoleErrors.length);
  console.log("failed_request_count=" + failedRequests.length);

  await browser.close();
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
