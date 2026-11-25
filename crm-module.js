const crmStoreKeys = {
  settings: "odooCrmSettings",
  opportunities: "odooCrmOpportunities",
  teams: "odooCrmTeams",
  stages: "odooCrmStages",
  tags: "odooCrmTags",
  lostReasons: "odooCrmLostReasons",
  activityTypes: "odooCrmActivityTypes",
  activityPlans: "odooCrmActivityPlans",
  levels: "odooCrmMemberLevels",
  activations: "odooCrmPartnerActivations",
  commissions: "odooCrmCommissionPlans",
  miningRequests: "odooCrmMiningRequests",
  visitRules: "odooCrmVisitRules",
  activities: "odooCrmActivities",
};

const crmDefaultSettings = {
  recurringRevenues: false,
  leads: false,
  multiTeams: false,
  membership: true,
  memberLabel: "Members",
  partnerWebsite: true,
  predictiveScoring: true,
  probabilityFields: ["state", "country", "phoneQuality", "emailQuality", "source", "language", "tags"],
  probabilityCutoffDate: "",
  ruleBasedAssignment: false,
  leadEnrichment: true,
  leadEnrichmentMode: "automatic",
  leadMining: true,
  visitsToLeads: true,
  automaticPoFrequency: "Monthly",
  minimumPoAmount: 0,
};

const crmProbabilityDimensions = [
  ["state", "State"], ["country", "Country"], ["phoneQuality", "Phone Quality"],
  ["emailQuality", "Email Quality"], ["source", "Source"], ["language", "Language"], ["tags", "Tags"],
];

const crmDefaultStages = [
  { id: "crm-stage-new", name: "New", probability: 10, won: false, folded: false, sequence: 10 },
  { id: "crm-stage-qualified", name: "Qualified", probability: 30, won: false, folded: false, sequence: 20 },
  { id: "crm-stage-proposition", name: "Proposition", probability: 70, won: false, folded: false, sequence: 30 },
  { id: "crm-stage-won", name: "Won", probability: 100, won: true, folded: false, sequence: 40 },
];

const crmDefaultTeams = [
  { id: "crm-team-sales", name: "Sales", alias: "info", aliasDomain: "", acceptEmailsFrom: "Everyone", leader: "Mitchell Admin", company: "My Company (San Francisco)", members: ["Mitchell Admin"], active: true, sequence: 10 },
  { id: "crm-team-website", name: "Website", alias: "website", aliasDomain: "", acceptEmailsFrom: "Everyone", leader: "Marc Demo", company: "My Company (San Francisco)", members: ["Marc Demo"], active: true, sequence: 20 },
  { id: "crm-team-pos", name: "Point of Sale", alias: "pos", aliasDomain: "", acceptEmailsFrom: "Everyone", leader: "Mitchell Admin", company: "My Company (San Francisco)", members: ["Mitchell Admin"], active: true, sequence: 30 },
  { id: "crm-team-presales", name: "Pre-Sales", alias: "pre-sales", aliasDomain: "", acceptEmailsFrom: "Everyone", leader: "Marc Demo", company: "My Company (San Francisco)", members: ["Marc Demo"], active: true, sequence: 40 },
  { id: "crm-team-kiosk", name: "Kiosk Sale Team", alias: "kiosk", aliasDomain: "", acceptEmailsFrom: "Everyone", leader: "Mitchell Admin", company: "My Company (San Francisco)", members: ["Mitchell Admin"], active: true, sequence: 50 },
];

const crmDefaultOpportunities = [
  { id: "crm-demo-1", name: "Devis pour 150 tapis", contactName: "OpenWood", company: "OpenWood", email: "contact@openwood.example", phone: "+1 555 010 1001", stage: "New", team: "Sales", salesperson: "Mitchell Admin", expectedRevenue: 40000, probability: 10, priority: 1, tags: ["Product"], source: "Website", country: "United States", expectedClosing: "2026-11-30", nextActivity: "Call", currency: "$", isLead: false, notes: "New flooring enquiry", recurringRevenue: 0 },
  { id: "crm-demo-2", name: "Quote for 12 Tables", contactName: "Will McEnroe", company: "Will McEnroe", email: "will@example.com", phone: "+1 555 010 1002", stage: "New", team: "Sales", salesperson: "Marc Demo", expectedRevenue: 40000, probability: 10, priority: 0, tags: ["Product"], source: "Referral", country: "United States", expectedClosing: "2026-12-04", nextActivity: "Email", currency: "$", isLead: false, notes: "", recurringRevenue: 0 },
  { id: "crm-demo-3", name: "Global Solutions: Furniture", contactName: "Ready Mat", company: "Global Solutions", email: "sales@globalsolutions.example", phone: "+1 555 010 2001", stage: "Qualified", team: "Sales", salesperson: "Mitchell Admin", expectedRevenue: 3800, probability: 30, priority: 2, tags: ["Design"], source: "Website", country: "United States", expectedClosing: "2026-12-08", nextActivity: "Meeting", currency: "$", isLead: false, notes: "Furniture requirements received", recurringRevenue: 0 },
  { id: "crm-demo-4", name: "Quote for 600 Chairs", contactName: "Erik N. French", company: "Erik N. French", email: "erik@example.com", phone: "+1 555 010 2002", stage: "Qualified", team: "Sales", salesperson: "Marc Demo", expectedRevenue: 22500, probability: 30, priority: 1, tags: ["Product"], source: "Phone", country: "United States", expectedClosing: "2026-12-12", nextActivity: "Call", currency: "$", isLead: false, notes: "", recurringRevenue: 0 },
  { id: "crm-demo-5", name: "Info about services", contactName: "Acme Corporation", company: "Acme Corporation", email: "procurement@acme.example", phone: "+1 555 010 2003", stage: "Qualified", team: "Pre-Sales", salesperson: "Mitchell Admin", expectedRevenue: 25000, probability: 30, priority: 0, tags: ["Product"], source: "Email", country: "United States", expectedClosing: "2026-12-15", nextActivity: "Email", currency: "$", isLead: false, notes: "Service proposal requested", recurringRevenue: 0 },
  { id: "crm-demo-6", name: "Modern Open Space", contactName: "Henry Jordan", company: "Modern Open Space", email: "henry@example.com", phone: "+1 555 010 3001", stage: "Proposition", team: "Sales", salesperson: "Mitchell Admin", expectedRevenue: 4500, probability: 70, priority: 2, tags: ["Information"], source: "Website", country: "United States", expectedClosing: "2026-11-29", nextActivity: "Call", currency: "$", isLead: false, notes: "Proposal delivered", recurringRevenue: 0 },
  { id: "crm-demo-7", name: "Office Design and Architecture", contactName: "Ready Mat", company: "Office Design", email: "design@example.com", phone: "+1 555 010 3002", stage: "Proposition", team: "Sales", salesperson: "Marc Demo", expectedRevenue: 9000, probability: 70, priority: 2, tags: ["Consulting"], source: "Referral", country: "United States", expectedClosing: "2026-12-02", nextActivity: "Meeting", currency: "$", isLead: false, notes: "", recurringRevenue: 0 },
  { id: "crm-demo-8", name: "5 VP Chairs", contactName: "Azure Interior", company: "Azure Interior", email: "orders@azure.example", phone: "+1 555 010 3003", stage: "Proposition", team: "Pre-Sales", salesperson: "Mitchell Admin", expectedRevenue: 5600, probability: 70, priority: 1, tags: ["Services"], source: "Email", country: "United States", expectedClosing: "2026-12-18", nextActivity: "Email", currency: "$", isLead: false, notes: "", recurringRevenue: 0 },
  { id: "crm-demo-9", name: "Need 20 Desks", contactName: "Henry Jordan", company: "Henry Jordan", email: "henry.jordan@example.com", phone: "+1 555 010 3004", stage: "Proposition", team: "Sales", salesperson: "Marc Demo", expectedRevenue: 60000, probability: 70, priority: 0, tags: ["Consulting"], source: "Website", country: "United States", expectedClosing: "2026-12-21", nextActivity: "Email", currency: "$", isLead: false, notes: "Large office furniture request", recurringRevenue: 0 },
  { id: "crm-demo-10", name: "Distributor Contract", contactName: "Gemini Furniture", company: "Gemini Furniture", email: "buying@gemini.example", phone: "+1 555 010 4001", stage: "Won", team: "Sales", salesperson: "Mitchell Admin", expectedRevenue: 19800, probability: 100, priority: 2, tags: ["Information", "Other"], source: "Referral", country: "United States", expectedClosing: "2026-11-08", nextActivity: "", currency: "$", isLead: false, notes: "Distributor agreement signed", recurringRevenue: 0 },
];

const crmDefaultData = {
  tags: ["Product", "Design", "Information", "Consulting", "Services", "Other"].map((name, index) => ({ id: `crm-tag-${index + 1}`, name, color: ["#ef6d55", "#ad5bb8", "#29a6d3", "#11a9a0", "#e1aa17", "#63758a"][index], active: true })),
  lostReasons: ["Too expensive", "Not enough stock", "Not interested", "Competition", "Other"].map((name, index) => ({ id: `crm-lost-${index + 1}`, name, active: true, sequence: index + 1 })),
  activityTypes: [
    { id: "crm-act-todo", name: "To-Do", summary: "To-Do", plannedIn: 5, timing: "after previous activity deadline", active: true },
    { id: "crm-act-email", name: "Email", summary: "Email", plannedIn: 0, timing: "after previous activity deadline", active: true },
    { id: "crm-act-call", name: "Call", summary: "Call", plannedIn: 2, timing: "after previous activity deadline", active: true },
    { id: "crm-act-meeting", name: "Meeting", summary: "Meeting", plannedIn: 0, timing: "after previous activity deadline", active: true },
    { id: "crm-act-document", name: "Document", summary: "Document", plannedIn: 5, timing: "after previous activity deadline", active: true },
    { id: "crm-act-signature", name: "Signature", summary: "Signature", plannedIn: 7, timing: "after previous activity deadline", active: true },
  ],
  activityPlans: [
    { id: "crm-plan-ref001", name: "REF0001", steps: 47, active: true }, { id: "crm-plan-ref002", name: "REF0002", steps: 21, active: true },
    { id: "crm-plan-ref003", name: "REF0003", steps: 49, active: true }, { id: "crm-plan-ref004", name: "REF0004", steps: 4, active: true },
    { id: "crm-plan-ref005", name: "REF0005", steps: 28, active: true }, { id: "crm-plan-ref006", name: "REF0006", steps: 36, active: true },
    { id: "crm-plan-ref007", name: "REF0007", steps: 31, active: true }, { id: "crm-plan-ref008", name: "REF0008", steps: 6, active: true },
    { id: "crm-plan-ref009", name: "REF0009", steps: 45, active: true }, { id: "crm-plan-ref010", name: "REF0010", steps: 20, active: true },
  ],
  levels: [
    { id: "crm-level-gold", name: "Gold", pricelist: "", active: true }, { id: "crm-level-silver", name: "Silver", pricelist: "", active: true },
    { id: "crm-level-bronze", name: "Bronze", pricelist: "", active: true }, { id: "crm-level-members", name: "Members", pricelist: "Professional Prices (USD)", active: true },
  ],
  activations: ["Fully Operational", "Ramp-up", "First Contact"].map((name, index) => ({ id: `crm-activation-${index + 1}`, name, active: true })),
  commissions: [], miningRequests: [], visitRules: [], activities: [],
};

function crmReadStorage(key, fallback) {
  try {
    const raw = localStorage.getItem(key);
    if (raw === null) return JSON.parse(JSON.stringify(fallback));
    const rows = JSON.parse(raw);
    return Array.isArray(rows) ? rows : JSON.parse(JSON.stringify(fallback));
  } catch { return JSON.parse(JSON.stringify(fallback)); }
}

function crmReadSettings() {
  try {
    const saved = JSON.parse(localStorage.getItem(crmStoreKeys.settings) || "{}");
    const allowed = new Set(crmProbabilityDimensions.map(([key]) => key));
    return {
      ...crmDefaultSettings,
      ...saved,
      predictiveScoring: true,
      probabilityFields: Array.isArray(saved.probabilityFields) ? saved.probabilityFields.filter((key) => allowed.has(key)) : [...crmDefaultSettings.probabilityFields],
      probabilityCutoffDate: /^\d{4}-\d{2}-\d{2}$/.test(String(saved.probabilityCutoffDate || "")) ? saved.probabilityCutoffDate : "",
    };
  }
  catch { return { ...crmDefaultSettings }; }
}

const crmWorkspace = {
  settings: crmReadSettings(),
  opportunities: crmReadStorage(crmStoreKeys.opportunities, crmDefaultOpportunities),
  teams: crmReadStorage(crmStoreKeys.teams, crmDefaultTeams),
  stages: crmReadStorage(crmStoreKeys.stages, crmDefaultStages),
  tags: crmReadStorage(crmStoreKeys.tags, crmDefaultData.tags),
  lostReasons: crmReadStorage(crmStoreKeys.lostReasons, crmDefaultData.lostReasons),
  activityTypes: crmReadStorage(crmStoreKeys.activityTypes, crmDefaultData.activityTypes),
  activityPlans: crmReadStorage(crmStoreKeys.activityPlans, crmDefaultData.activityPlans),
  levels: crmReadStorage(crmStoreKeys.levels, crmDefaultData.levels),
  activations: crmReadStorage(crmStoreKeys.activations, crmDefaultData.activations),
  commissions: crmReadStorage(crmStoreKeys.commissions, crmDefaultData.commissions),
  miningRequests: crmReadStorage(crmStoreKeys.miningRequests, crmDefaultData.miningRequests),
  visitRules: crmReadStorage(crmStoreKeys.visitRules, crmDefaultData.visitRules),
  activities: crmReadStorage(crmStoreKeys.activities, crmDefaultData.activities),
  view: "kanban", teamFilter: "All Teams", quickFilter: "My Opportunities", search: "", dropdown: "", quickCreateStage: "",
  activeRecordId: null, activityFilter: "Planned", chartMetric: "Expected Revenue", selectedIds: new Set(), draggingId: "",
  teamEditor: null, teamStatusFilter: "All", teamHasOpportunities: "", teamGroupBy: "", teamFilterDropdown: "",
  forecastFilters: [], forecastGroupBy: "Month",
  probabilityDraft: null,
  stageMove: null,
};

const crmSchemas = {
  "crm-sales-teams": { key: "teams", title: "Sales Teams", description: "Group salespeople by region or sales channel.", columns: [["Sales Team", "name"], ["Alias", "alias"], ["Team Leader", "leader"], ["Company", "company"]], fields: [["name", "Sales Team", "text", true], ["alias", "Alias", "text"], ["leader", "Team Leader", "text"], ["company", "Company", "text"], ["members", "Members", "text"], ["active", "Active", "checkbox"]] },
  "crm-activity-types": { key: "activityTypes", title: "Activity Types", description: "Configure follow-up actions and deadlines.", columns: [["Name", "name"], ["Default Summary", "summary"], ["Planned in", "plannedIn"], ["Type", "timing"], ["Default User", "defaultUser"]], fields: [["name", "Name", "text", true], ["summary", "Default Summary", "text"], ["plannedIn", "Planned in (days)", "number"], ["timing", "Deadline", "select", ["after previous activity deadline", "after current date"]], ["defaultUser", "Default User", "text"], ["active", "Active", "checkbox"]] },
  "crm-activity-plans": { key: "activityPlans", title: "Lead Activity Plans", description: "Assign a sequence of activities to leads and opportunities.", columns: [["Name", "name"], ["Steps Count", "steps"]], fields: [["name", "Name", "text", true], ["steps", "Steps Count", "number"], ["active", "Active", "checkbox"]] },
  "crm-stages": { key: "stages", title: "Stages", description: "Define the qualification steps and expected success rate of your pipeline.", columns: [["Stage Name", "name"], ["Probability (%)", "probability"], ["Is Won", "won"]], fields: [["name", "Stage Name", "text", true], ["probability", "Probability (%)", "number"], ["won", "Is Won", "checkbox"], ["folded", "Folded in Pipeline", "checkbox"], ["sequence", "Sequence", "number"]] },
  "crm-tags": { key: "tags", title: "Tags", description: "Label leads and opportunities for fast filtering.", columns: [["Name", "name"], ["Color", "color"]], fields: [["name", "Name", "text", true], ["color", "Color", "color"], ["active", "Active", "checkbox"]] },
  "crm-lost-reasons": { key: "lostReasons", title: "Lost Reasons", description: "Capture why an opportunity was lost.", columns: [["Name", "name"], ["Active", "active"]], fields: [["name", "Name", "text", true], ["active", "Active", "checkbox"], ["sequence", "Sequence", "number"]] },
  "crm-levels": { key: "levels", title: "Levels", description: "Set membership tiers and their linked pricelists.", columns: [["Level Name", "name"], ["Pricelist", "pricelist"]], fields: [["name", "Level Name", "text", true], ["pricelist", "Pricelist", "text"], ["active", "Active", "checkbox"]] },
  "crm-partner-activations": { key: "activations", title: "Partner Activations", description: "Track onboarding milestones for partners.", columns: [["Name", "name"], ["Active", "active"]], fields: [["name", "Name", "text", true], ["active", "Active", "checkbox"]] },
  "crm-commission-plans": { key: "commissions", title: "Commission Plans", description: "Calculate partner commissions from confirmed sales revenue.", columns: [["Name", "name"], ["Commission (%)", "rate"], ["Purchase Default Product", "product"]], fields: [["name", "Name", "text", true], ["rate", "Commission (%)", "number"], ["product", "Purchase Default Product", "text"], ["level", "Member Level", "select", "levels"], ["active", "Active", "checkbox"]] },
  "crm-lead-mining-requests": { key: "miningRequests", title: "Lead Mining Requests", description: "Define countries and industries for prospect searches.", columns: [["Request Number", "name"], ["Number of Leads", "count"], ["Countries", "countries"], ["Industries", "industries"], ["Status", "status"]], fields: [["name", "Request Number", "text", true], ["count", "Number of Leads", "number"], ["countries", "Countries", "text"], ["industries", "Industries", "text"], ["status", "Status", "select", ["Draft", "Waiting for Credits", "Done"]]] },
  "crm-visits-to-leads-rules": { key: "visitRules", title: "Visits to Leads Rules", description: "Create rules that convert website visits into B2B leads.", columns: [["Rule Name", "name"], ["Type", "type"]], fields: [["name", "Rule Name", "text", true], ["website", "Website", "text"], ["type", "Type", "select", ["Visitor country", "Visited page", "Visited product category"]], ["country", "Country", "text"], ["assignTo", "Assign To", "text"], ["active", "Active", "checkbox"]] },
};

function crmPersist(name) {
  const key = crmStoreKeys[name];
  if (!key) return false;
  try { localStorage.setItem(key, JSON.stringify(crmWorkspace[name])); return true; }
  catch { showToast("CRM could not save this change in the current browser."); return false; }
}

function crmPersistSettings() {
  try { localStorage.setItem(crmStoreKeys.settings, JSON.stringify(crmWorkspace.settings)); return true; }
  catch { showToast("CRM settings could not be saved in this browser."); return false; }
}

function crmFormatMoney(amount, currency = "$") {
  const formatted = Number(amount || 0).toLocaleString("en-US", { maximumFractionDigits: 0 });
  return `${currency === "৳" ? "৳ " : `${currency} `}${formatted}`;
}

function crmEscapeAttr(value) { return escapeHtml(value).replace(/`/g, "&#96;"); }
function crmId(prefix = "crm") { return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`; }
function crmStageByName(name) { return crmWorkspace.stages.find((item) => String(item.name).toLowerCase() === String(name || "").toLowerCase()); }
function crmStageProbability(name) { return Number(crmStageByName(name)?.probability ?? 10); }
function crmExpectedRevenue(row) { return Number(row.expectedRevenue || 0) * Number(row.probability ?? crmStageProbability(row.stage)) / 100; }
function crmActiveRecords() { return crmWorkspace.opportunities.filter((row) => row.active !== false && !row.lost); }
function crmVisibleRecords({ onlyLeads = false, onlyOpportunities = false } = {}) {
  const query = crmWorkspace.search.trim().toLowerCase();
  return crmActiveRecords().filter((row) => {
    if (!crmWorkspace.settings.leads && row.isLead) return false;
    if (onlyLeads && !row.isLead) return false;
    if (onlyOpportunities && row.isLead) return false;
    if (crmWorkspace.teamFilter !== "All Teams" && row.team !== crmWorkspace.teamFilter) return false;
    if (!onlyLeads && crmWorkspace.quickFilter === "My Leads" && !row.isLead) return false;
    if (!onlyLeads && crmWorkspace.quickFilter === "My Opportunities" && row.isLead) return false;
    if (!onlyLeads && crmWorkspace.quickFilter === "All Leads" && !row.isLead) return false;
    if (!onlyLeads && crmWorkspace.quickFilter === "All Opportunities" && row.isLead) return false;
    if (crmWorkspace.quickFilter === "Unassigned" && (row.salesperson || row.team)) return false;
    if (query && ![row.name, row.contactName, row.company, row.email, row.phone, row.salesperson, row.team, row.stage, row.source, ...(row.tags || [])].join(" ").toLowerCase().includes(query)) return false;
    return true;
  });
}

function crmLeadScore(row, dimensions = crmDefaultSettings.probabilityFields) {
  const stage = crmStageProbability(row.stage);
  const selected = new Set(dimensions);
  const phoneQuality = selected.has("phoneQuality") && String(row.phone || "").replace(/\D/g, "").length >= 7 ? 10 : 0;
  const emailQuality = selected.has("emailQuality") && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(row.email || "")) ? 10 : 0;
  const sourceQuality = selected.has("source") ? (({ Website: 15, Referral: 18, Phone: 12, Email: 10, "Lead Mining": 5 })[row.source] || 7) : 0;
  const countryQuality = selected.has("country") && String(row.country || "").trim() ? 8 : 0;
  const regionQuality = selected.has("state") && String(row.state || "").trim() ? 5 : 0;
  const tagQuality = selected.has("tags") ? Math.min(8, (row.tags || []).length * 2) : 0;
  const languageQuality = selected.has("language") && String(row.language || "").trim() ? 5 : 0;
  const stageScore = dimensions.length ? stage * 0.45 : stage;
  return Math.min(99, Math.max(0, Math.round(stageScore + phoneQuality + emailQuality + sourceQuality + countryQuality + regionQuality + tagQuality + languageQuality)));
}

function crmComputeProbability(row, dimensions = crmDefaultSettings.probabilityFields) {
  const stage = crmStageProbability(row.stage);
  if (crmStageByName(row.stage)?.won) return 100;
  const score = crmLeadScore(row, dimensions);
  const blended = Math.round(stage * 0.65 + score * 0.35);
  return Math.max(1, Math.min(99, blended));
}

function crmCompanyNames() {
  const contacts = [...(state.contacts || []), ...(state.salesCustomers || [])].map((item) => item.name).filter(Boolean);
  return [...new Set([...contacts, ...crmWorkspace.opportunities.map((item) => item.company || item.contactName).filter(Boolean)])].sort((a, b) => a.localeCompare(b));
}

function crmQuickContact(name) {
  const wanted = String(name || "").trim().toLowerCase();
  if (!wanted) return null;
  const shared = [...(state.contacts || []), ...(state.salesCustomers || [])].find((item) => String(item.name || "").trim().toLowerCase() === wanted);
  if (shared) return shared;
  const opportunity = crmWorkspace.opportunities.find((item) => String(item.contactName || item.company || "").trim().toLowerCase() === wanted);
  return opportunity ? { name: opportunity.contactName || opportunity.company, email: opportunity.email, phone: opportunity.phone } : null;
}

function crmQuickIcon(name) {
  const paths = {
    contact: '<circle cx="12" cy="8" r="3.2"/><path d="M5.2 19c.7-3 3.1-4.6 6.8-4.6s6.1 1.6 6.8 4.6"/>',
    opportunity: '<rect x="4" y="5" width="16" height="15" rx="1.5"/><path d="M9 5V3h6v2M4 10h16"/>',
    email: '<rect x="3.5" y="5.5" width="17" height="13" rx="1.5"/><path d="m4.5 7 7.5 6 7.5-6"/>',
    phone: '<rect x="7" y="2.5" width="10" height="19" rx="1.8"/><path d="M10 5h4M11 18.5h2"/>',
    revenue: '<rect x="3.5" y="5" width="17" height="14" rx="1.5"/><path d="M7 9h2M7 12h2M7 15h2m3-6h5m-5 3h5m-5 3h5"/>',
    trash: '<path d="M4 7h16M9 7V4h6v3m3 0-.8 13H6.8L6 7m4 3v7m4-7v7"/>',
  };
  return `<svg viewBox="0 0 24 24" aria-hidden="true">${paths[name] || paths.contact}</svg>`;
}

function crmFindSharedContact(record) {
  const contacts = [...(state.contacts || []), ...(state.salesCustomers || [])];
  const emailDomain = String(record.email || "").split("@")[1]?.toLowerCase();
  const byDomain = emailDomain && contacts.find((contact) => String(contact.email || "").split("@")[1]?.toLowerCase() === emailDomain);
  const wantedName = String(record.company || record.contactName || "").trim().toLowerCase();
  const byName = wantedName && contacts.find((contact) => String(contact.name || "").trim().toLowerCase() === wantedName);
  return byDomain || byName || null;
}

function crmEnrichFromSharedContact(record) {
  const contact = crmFindSharedContact(record);
  if (!contact) return false;
  record.company = record.company || contact.company || contact.name || "";
  record.phone = record.phone || contact.phone || "";
  record.country = record.country || contact.country || "";
  record.memberLevel = record.memberLevel || contact.memberLevel || contact.membershipLevel || "";
  return true;
}

function crmAvatar(name = "") {
  return `<span class="crm-avatar" aria-label="${crmEscapeAttr(name || "Salesperson")}">${escapeHtml(initials(name || "S"))}</span>`;
}

function crmForecastFilterIcon() {
  return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16l-6.25 7.1v5.05L10.25 19v-6.9L4 5Z"/></svg>';
}

function crmForecastSearchMarkup() {
  const filterIconButton = () => `<button type="button" class="inventory-product-filter-toggle has-filters is-facet-toggle crm-forecast-filter-trigger" data-crm-action="forecast-filter-dropdown" aria-expanded="${crmWorkspace.dropdown === "forecast-filters"}" aria-label="Filters" title="Filters">${crmForecastFilterIcon()}</button>`;
  const filterChips = crmWorkspace.forecastFilters.length
    ? crmWorkspace.forecastFilters.map((filter) => `<span class="inventory-product-filter-chip crm-forecast-facet">${filterIconButton()}<span class="inventory-product-filter-chip-label">${escapeHtml(filter)}</span><button type="button" class="inventory-product-filter-chip-remove" data-crm-action="forecast-remove-filter" data-filter="${crmEscapeAttr(filter)}" aria-label="Remove ${crmEscapeAttr(filter)}" title="Remove filter">×</button></span>`).join("")
    : filterIconButton();
  const groupChip = crmWorkspace.forecastGroupBy
    ? `<span class="inventory-product-filter-chip crm-forecast-facet is-group"><span class="crm-forecast-group-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5"/></svg></span><span class="inventory-product-filter-chip-label">Expected Closing: ${escapeHtml(crmWorkspace.forecastGroupBy)}</span><button type="button" class="inventory-product-filter-chip-remove" data-crm-action="forecast-group" data-group="" aria-label="Remove grouping" title="Remove grouping">×</button></span>`
    : "";
  const filterMenu = crmWorkspace.dropdown === "forecast-filters" ? `<div class="crm-forecast-filter-menu"><strong>Filters</strong>${["Upcoming Closings", "My Opportunities"].map((filter) => `<button type="button" class="${crmWorkspace.forecastFilters.includes(filter) ? "is-current" : ""}" data-crm-action="forecast-toggle-filter" data-filter="${crmEscapeAttr(filter)}">${crmWorkspace.forecastFilters.includes(filter) ? "✓" : "□"} ${escapeHtml(filter)}</button>`).join("")}<hr><strong>Group By</strong><button type="button" class="${crmWorkspace.forecastGroupBy === "Month" ? "is-current" : ""}" data-crm-action="forecast-group" data-group="Month">${crmWorkspace.forecastGroupBy === "Month" ? "✓" : "□"} Expected Closing: Month</button><button type="button" class="${!crmWorkspace.forecastGroupBy ? "is-current" : ""}" data-crm-action="forecast-group" data-group="">${!crmWorkspace.forecastGroupBy ? "✓" : "□"} No grouping</button></div>` : "";
  return `<div class="search-box sales-search inventory-products-search crm-search-box crm-forecast-search" role="search">${icon("search")}<div class="inventory-product-filter-chips crm-forecast-facets">${filterChips}${groupChip}</div><input id="crm-search" type="search" value="${crmEscapeAttr(crmWorkspace.search)}" placeholder="Search..." autocomplete="off" aria-label="Search CRM records">${filterMenu}</div>`;
}

function crmAppToolbar(title = "Pipeline", options = {}) {
  const viewNames = options.viewNames || [["kanban", "kanban"], ["list", "list"], ["calendar", "calendar"], ["pivot", "pivot"], ["graph", "chart"], ["map", "map"], ["activity", "clock"]];
  const defaultNewButton = `<button type="button" class="action-button" data-action="new-record">New</button>`;
  const forecastActions = `<div class="crm-toolbar-leading"><button type="button" class="action-button" data-action="new-record">New</button><div class="crm-team-wrap"><button type="button" class="crm-team-filter" data-crm-action="toggle-dropdown" data-dropdown="teams" aria-expanded="${crmWorkspace.dropdown === "teams"}">${escapeHtml(crmWorkspace.teamFilter)} <span>⌄</span></button>${crmWorkspace.dropdown === "teams" ? `<div class="crm-toolbar-dropdown"><button type="button" class="${crmWorkspace.teamFilter === "All Teams" ? "is-current" : ""}" data-crm-action="team-filter" data-team="All Teams">All Teams</button>${crmWorkspace.teams.filter((team) => team.active !== false).map((team) => `<button type="button" class="${crmWorkspace.teamFilter === team.name ? "is-current" : ""}" data-crm-action="team-filter" data-team="${crmEscapeAttr(team.name)}">${escapeHtml(team.name)}</button>`).join("")}<div class="crm-dropdown-divider"></div><button type="button" data-module-page="crm-sales-teams">Manage Teams</button></div>` : ""}</div><button type="button" class="crm-more-button" data-crm-action="toggle-dropdown" data-dropdown="forecast-more" aria-label="More actions">⋮</button>${crmWorkspace.dropdown === "forecast-more" ? `<div class="crm-toolbar-dropdown crm-more-dropdown"><button type="button" data-crm-action="export">Export</button><button type="button" data-crm-action="toggle-dropdown" data-dropdown="forecast-more">Close</button></div>` : ""}</div>`;
  const generationActions = [
    crmWorkspace.settings.leads ? `<button type="button" data-crm-action="new-lead">Create Lead</button>` : "",
    crmWorkspace.settings.leadMining && crmWorkspace.settings.leads ? `<button type="button" data-crm-action="open-mining">Lead Mining Request</button>` : "",
    crmWorkspace.settings.leadEnrichment || crmWorkspace.settings.leadMining || crmWorkspace.settings.visitsToLeads ? `<button type="button" data-crm-action="view-services">View My Services</button>` : "",
  ].filter(Boolean).join("");
  const quickFilters = ["My Opportunities", ...(crmWorkspace.settings.leads ? ["My Leads"] : []), "All Opportunities", ...(crmWorkspace.settings.leads ? ["All Leads"] : []), "Unassigned"];
  const toolbarActions = options.simple ? `<div class="crm-toolbar-leading">${options.newButton || defaultNewButton}</div>` : options.forecast ? forecastActions : `<div class="crm-toolbar-leading">${options.newButton || defaultNewButton}${generationActions ? `<div class="crm-generate-wrap"><button type="button" class="action-button secondary" data-crm-action="toggle-dropdown" data-dropdown="generate" aria-expanded="${crmWorkspace.dropdown === "generate"}">Generate <span>⌄</span></button>${crmWorkspace.dropdown === "generate" ? `<div class="crm-toolbar-dropdown">${generationActions}</div>` : ""}</div>` : ""}<div class="crm-team-wrap"><button type="button" class="crm-team-filter" data-crm-action="toggle-dropdown" data-dropdown="teams" aria-expanded="${crmWorkspace.dropdown === "teams"}">${escapeHtml(crmWorkspace.teamFilter)} <span>⌄</span></button>${crmWorkspace.dropdown === "teams" ? `<div class="crm-toolbar-dropdown"><button type="button" class="${crmWorkspace.teamFilter === "All Teams" ? "is-current" : ""}" data-crm-action="team-filter" data-team="All Teams">All Teams</button>${crmWorkspace.teams.filter((team) => team.active !== false && ["Sales", "Pre-Sales", "Kiosk Sale Team"].includes(team.name)).map((team) => `<button type="button" class="${crmWorkspace.teamFilter === team.name ? "is-current" : ""}" data-crm-action="team-filter" data-team="${crmEscapeAttr(team.name)}">${escapeHtml(team.name)}</button>`).join("")}<div class="crm-dropdown-divider"></div><button type="button" data-module-page="crm-sales-teams">Manage Teams</button></div>` : ""}</div><div class="crm-filter-wrap"><button type="button" class="crm-filter-mode" data-crm-action="toggle-dropdown" data-dropdown="filters" aria-expanded="${crmWorkspace.dropdown === "filters"}">${escapeHtml(crmWorkspace.quickFilter)} <span>⌄</span></button>${crmWorkspace.dropdown === "filters" ? `<div class="crm-toolbar-dropdown">${quickFilters.map((filter) => `<button type="button" class="${crmWorkspace.quickFilter === filter ? "is-current" : ""}" data-crm-action="quick-filter" data-filter="${crmEscapeAttr(filter)}">${escapeHtml(filter)}</button>`).join("")}</div>` : ""}</div><button type="button" class="crm-more-button" data-crm-action="toggle-dropdown" data-dropdown="more" aria-label="More actions">⋮</button>${crmWorkspace.dropdown === "more" ? `<div class="crm-toolbar-dropdown crm-more-dropdown"><button type="button" data-crm-action="export">Export current view</button><button type="button" data-crm-action="import">Import records</button><button type="button" data-crm-action="toggle-favorites">My Opportunities</button></div>` : ""}</div>`;
  const searchMarkup = options.forecast ? crmForecastSearchMarkup()
    : `<label class="crm-search-box"><span class="crm-search-filter-mark">⌕</span><span class="crm-search-chip">${escapeHtml(options.searchFilter || crmWorkspace.quickFilter)} ${options.simple ? "" : `<button type="button" data-crm-action="clear-quick-filter" aria-label="Clear filter">×</button>`}</span><input id="crm-search" type="search" value="${crmEscapeAttr(crmWorkspace.search)}" placeholder="Search..." autocomplete="off" aria-label="Search CRM records"></label>`;
  const viewsMarkup = `<div class="crm-view-controls" role="group" aria-label="CRM view">${viewNames.map(([name, glyph]) => `<button type="button" class="${(options.simple ? name === "list" : crmWorkspace.view === name) ? "is-active" : ""}" data-crm-action="view" data-view="${name}" aria-label="${name} view" title="${name}">${icon(glyph)}</button>`).join("")}</div>`;
  return `<section class="crm-page-toolbar ${options.forecast ? "crm-forecast-toolbar" : ""}"><div class="crm-toolbar-title">${toolbarActions}${options.hideTitle ? "" : `<h1>${escapeHtml(title)}</h1>`}</div>${searchMarkup}${viewsMarkup}</section>`;
}

function crmPriorityStars(priority) {
  return `<span class="crm-priority" aria-label="Priority ${Number(priority || 0)}">${[0, 1, 2].map((index) => `<span class="${index < Number(priority || 0) ? "is-filled" : ""}">★</span>`).join("")}</span>`;
}

function crmNextActivityIcon(name = "") {
  const type = String(name).toLowerCase();
  if (!type) return "";
  if (type.includes("call")) return `<span class="crm-activity-icon crm-activity-call" title="Call">☎</span>`;
  if (type.includes("email")) return `<span class="crm-activity-icon crm-activity-email" title="Email">✉</span>`;
  if (type.includes("meeting")) return `<span class="crm-activity-icon crm-activity-meeting" title="Meeting">◷</span>`;
  return `<span class="crm-activity-icon" title="${crmEscapeAttr(name)}">☷</span>`;
}

function crmTagPills(tags = []) {
  return (tags || []).slice(0, 3).map((name) => {
    const tag = crmWorkspace.tags.find((item) => item.name === name);
    return `<span class="crm-tag-pill" style="--crm-tag-color:${crmEscapeAttr(tag?.color || "#2ea6d2")}">${escapeHtml(name)}</span>`;
  }).join("");
}

function crmOpportunityCard(record) {
  return `<article class="crm-opportunity-card" data-crm-card="${crmEscapeAttr(record.id)}" data-crm-searchable="${crmEscapeAttr([record.name, record.company, record.contactName, record.email, record.salesperson, record.team, ...(record.tags || [])].join(" ").toLowerCase())}" draggable="true" tabindex="0" role="button" aria-label="Open ${crmEscapeAttr(record.name)}"><button type="button" class="crm-card-name" data-crm-action="open-record" data-id="${crmEscapeAttr(record.id)}">${escapeHtml(record.name)}</button><div class="crm-card-value">${crmFormatMoney(record.expectedRevenue, record.currency)}</div><div class="crm-card-contact">${escapeHtml(record.contactName || record.company || "")}</div>${record.company && record.company !== record.contactName ? `<div class="crm-card-company">${escapeHtml(record.company)}</div>` : ""}<div class="crm-card-tags">${crmTagPills(record.tags)}</div><footer>${crmPriorityStars(record.priority)}${crmNextActivityIcon(record.nextActivity)}<span class="crm-card-spacer"></span><span class="crm-team-pill">${escapeHtml(record.team || "")}</span>${crmAvatar(record.salesperson)}</footer></article>`;
}

function crmQuickCreate(stage) {
  if (crmWorkspace.quickCreateStage !== stage.name) return "";
  const contacts = crmCompanyNames().map((name) => `<option value="${crmEscapeAttr(name)}">`).join("");
  return `<form class="crm-quick-create" data-crm-quick-create="${crmEscapeAttr(stage.name)}">
    <label class="crm-quick-field crm-quick-contact"><span>${crmQuickIcon("contact")}</span><input name="contactName" list="crm-quick-contact-options" placeholder="Contact" maxlength="120" aria-label="Contact" autocomplete="off"><datalist id="crm-quick-contact-options">${contacts}</datalist></label>
    <label class="crm-quick-field"><span>${crmQuickIcon("opportunity")}</span><input name="name" placeholder="Opportunity's Name" required maxlength="120" aria-label="Opportunity's Name"></label>
    <label class="crm-quick-field"><span>${crmQuickIcon("email")}</span><input type="email" name="email" placeholder="Contact Email" maxlength="180" aria-label="Contact Email"></label>
    <label class="crm-quick-field"><span>${crmQuickIcon("phone")}</span><input type="tel" name="phone" placeholder="Contact Phone" maxlength="60" aria-label="Contact Phone"></label>
    <div class="crm-quick-bottom"><label class="crm-quick-revenue"><span>${crmQuickIcon("revenue")}</span><span>$</span><input name="expectedRevenue" type="number" min="0" step="0.01" value="0.00" aria-label="Expected Revenue"></label><input type="hidden" name="priority" value="0"><div class="crm-quick-rating" role="group" aria-label="Priority">${[1, 2, 3].map((value) => `<button type="button" data-crm-action="quick-priority" data-rating="${value}" aria-label="${value} star${value === 1 ? "" : "s"}" aria-pressed="false">☆</button>`).join("")}</div></div>
    <div class="crm-quick-actions"><button class="action-button" type="submit">Add</button><button type="button" class="action-button secondary" data-crm-action="edit-quick-create">Edit</button><button type="button" class="crm-quick-delete" data-crm-action="discard-quick-create" aria-label="Delete" title="Delete">${crmQuickIcon("trash")}</button></div>
  </form>`;
}

function crmRenderKanban(records = crmVisibleRecords()) {
  const stages = crmWorkspace.stages.filter((stage) => !stage.folded || records.some((row) => row.stage === stage.name)).sort((a, b) => Number(a.sequence || 0) - Number(b.sequence || 0));
  const columns = stages.map((stage) => {
    const rows = records.filter((row) => row.stage === stage.name && !row.isLead);
    const total = rows.reduce((sum, row) => sum + Number(row.expectedRevenue || 0), 0);
    const progress = Math.max(0, Math.min(100, rows.length ? rows.reduce((sum, row) => sum + Number(row.probability || 0), 0) / rows.length : 0));
    const pendingMove = crmWorkspace.stageMove?.stage === stage.name;
    return `<section class="crm-stage-column ${pendingMove ? "is-processing is-drop-target" : ""}" data-crm-stage-drop="${crmEscapeAttr(stage.name)}" ${pendingMove ? 'aria-busy="true"' : ""}><header class="crm-stage-header"><div class="crm-stage-heading"><h2>${escapeHtml(stage.name)}</h2><button type="button" class="crm-stage-add" data-crm-action="quick-create" data-stage="${crmEscapeAttr(stage.name)}" aria-label="Add to ${crmEscapeAttr(stage.name)}">+</button></div><div class="crm-stage-totals"><div class="crm-stage-progress" role="img" aria-label="Average probability ${progress.toFixed(0)} percent"><i style="width:${progress.toFixed(0)}%"></i><b style="left:${progress.toFixed(0)}%"></b></div><strong>${crmFormatMoney(total, rows[0]?.currency || "$")}</strong>${rows.length > 0 ? `<span>${rows.length}</span>` : ""}</div>${crmQuickCreate(stage)}</header><div class="crm-stage-cards">${rows.length ? rows.map(crmOpportunityCard).join("") : `<div class="crm-stage-empty">No opportunities</div>`}${pendingMove ? `<div class="crm-stage-busy" role="status"><span class="crm-spinner" aria-hidden="true"></span>Updating opportunity…</div>` : ""}</div></section>`;
  }).join("");
  return `<div class="crm-pipeline-scroll"><div class="crm-pipeline-board">${columns}<button type="button" class="crm-add-stage-column" data-module-page="crm-stages" title="Manage pipeline stages">››</button></div></div>`;
}

function crmRenderList(records, { leadsOnly = false } = {}) {
  const rows = records.map((record) => `<tr data-crm-searchable="${crmEscapeAttr([record.name, record.contactName, record.company, record.email, record.salesperson, record.team, record.stage, ...(record.tags || [])].join(" ").toLowerCase())}"><td><input type="checkbox" data-crm-select="${crmEscapeAttr(record.id)}" ${crmWorkspace.selectedIds.has(String(record.id)) ? "checked" : ""} aria-label="Select ${crmEscapeAttr(record.name)}"></td><td>${crmPriorityStars(record.priority)}</td><td><button type="button" class="crm-table-link" data-crm-action="open-record" data-id="${crmEscapeAttr(record.id)}">${escapeHtml(record.name)}</button>${record.isLead ? '<span class="crm-lead-badge">Lead</span>' : ""}<small>${escapeHtml(record.company || "")}</small></td><td>${escapeHtml(record.contactName || "")}</td><td><a href="mailto:${crmEscapeAttr(record.email || "")}">${escapeHtml(record.email || "")}</a></td><td>${escapeHtml(record.salesperson || "")}</td><td>${escapeHtml(record.team || "")}</td><td><span class="crm-stage-badge">${escapeHtml(record.stage || "New")}</span></td><td>${crmFormatMoney(record.expectedRevenue, record.currency)}</td><td>${Number(record.probability || 0)}%</td><td>${escapeHtml(record.nextActivity || "—")}</td></tr>`).join("");
  return `<div class="crm-data-table-wrap"><table class="crm-data-table"><thead><tr><th><input type="checkbox" data-crm-select-all aria-label="Select all"></th><th>Priority</th><th>${leadsOnly ? "Lead" : "Opportunity"}</th><th>Contact</th><th>Email</th><th>Salesperson</th><th>Sales Team</th><th>Stage</th><th>Expected Revenue</th><th>Probability</th><th>Next Activity</th></tr></thead><tbody>${rows || `<tr><td colspan="11"><div class="crm-empty-state"><div>▤</div><h2>No records found</h2><p>Adjust the search or create a new ${leadsOnly ? "lead" : "opportunity"}.</p><button class="action-button" type="button" data-action="new-record">New</button></div></td></tr>`}</tbody></table></div>`;
}

function crmRenderCalendar(records) {
  const upcoming = [...records].sort((a, b) => String(a.expectedClosing || "").localeCompare(String(b.expectedClosing || "")));
  const month = new Date();
  month.setDate(1);
  month.setMonth(month.getMonth() + Number(crmWorkspace.calendarOffset || 0));
  const firstDay = new Date(month.getFullYear(), month.getMonth(), 1);
  const monthLabel = month.toLocaleDateString("en-US", { month: "long", year: "numeric" });
  return `<section class="crm-calendar-view"><div class="crm-calendar-toolbar"><button type="button" data-crm-action="calendar-step" data-step="-1" aria-label="Previous month">‹</button><strong>${monthLabel}</strong><button type="button" data-crm-action="calendar-step" data-step="1" aria-label="Next month">›</button><span>Expected Closing</span></div><div class="crm-calendar-grid">${["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"].map((day) => `<div class="crm-calendar-day-name">${day}</div>`).join("")}${Array.from({ length: 42 }, (_, index) => { const date = new Date(firstDay); date.setDate(index - firstDay.getDay() + 1); const key = inputLocalDate(date); const rows = upcoming.filter((item) => item.expectedClosing === key); const inMonth = date.getMonth() === month.getMonth(); return `<div class="crm-calendar-day ${inMonth ? "" : "is-outside-month"}"><span>${date.getDate()}</span>${rows.slice(0, 2).map((row) => `<button type="button" data-crm-action="open-record" data-id="${crmEscapeAttr(row.id)}">${escapeHtml(row.name)}</button>`).join("")}</div>`; }).join("")}</div></section>`;
}

function crmRenderGraph(records) {
  const groups = crmWorkspace.stages.map((stage) => ({ name: stage.name, rows: records.filter((row) => row.stage === stage.name), value: records.filter((row) => row.stage === stage.name).reduce((sum, row) => sum + Number(row.expectedRevenue || 0), 0) }));
  const max = Math.max(1, ...groups.map((group) => group.value));
  return `<section class="crm-graph-view"><header><div><h2>Pipeline Analysis</h2><p>Opportunities grouped by stage · Expected Revenue</p></div><select data-crm-graph-metric><option>Expected Revenue</option><option>Count</option><option>Weighted Revenue</option></select></header><div class="crm-graph-chart">${groups.map((group) => { const value = crmWorkspace.chartMetric === "Count" ? group.rows.length : crmWorkspace.chartMetric === "Weighted Revenue" ? group.rows.reduce((sum, row) => sum + crmExpectedRevenue(row), 0) : group.value; const percent = Math.max(3, value / max * 100); return `<button type="button" class="crm-graph-bar" data-crm-action="filter-stage" data-stage="${crmEscapeAttr(group.name)}"><span>${crmFormatMoney(value, "$")}</span><i style="height:${percent}%"></i><small>${escapeHtml(group.name)}</small></button>`; }).join("")}</div></section>`;
}

function crmRenderPivot(records) {
  const rows = crmWorkspace.stages.map((stage) => { const subset = records.filter((item) => item.stage === stage.name); return `<tr><th>${escapeHtml(stage.name)}</th><td>${subset.length}</td><td>${crmFormatMoney(subset.reduce((sum, item) => sum + Number(item.expectedRevenue || 0), 0), "$")}</td><td>${crmFormatMoney(subset.reduce((sum, item) => sum + crmExpectedRevenue(item), 0), "$")}</td></tr>`; }).join("");
  return `<section class="crm-pivot-view"><header><div><h2>Pipeline</h2><p>Expected Revenue · Grouped by Stage</p></div><button type="button" class="action-button secondary" data-crm-action="export">Insert in Spreadsheet</button></header><table><thead><tr><th>Stage</th><th>Count</th><th>Expected Revenue</th><th>Weighted Revenue</th></tr></thead><tbody>${rows}<tr class="crm-total-row"><th>Total</th><td>${records.length}</td><td>${crmFormatMoney(records.reduce((sum, row) => sum + Number(row.expectedRevenue || 0), 0), "$")}</td><td>${crmFormatMoney(records.reduce((sum, row) => sum + crmExpectedRevenue(row), 0), "$")}</td></tr></tbody></table></section>`;
}

function crmRenderMap(records) {
  const countries = [...new Set(records.map((row) => row.country).filter(Boolean))];
  return `<section class="crm-map-view"><div class="crm-map-illustration"><div class="crm-map-water"><i></i><b></b><em></em><span></span></div>${records.filter((row) => row.country).slice(0, 12).map((row, index) => `<button type="button" class="crm-map-pin pin-${index % 8}" data-crm-action="open-record" data-id="${crmEscapeAttr(row.id)}" title="${crmEscapeAttr(row.name)}">●</button>`).join("")}</div><aside><h2>Leads by Country</h2><p>Prospects grouped by their company location.</p>${countries.map((country) => `<div class="crm-country-row"><span>${escapeHtml(country)}</span><b>${records.filter((row) => row.country === country).length}</b></div>`).join("") || "<p>No country data available.</p>"}</aside></section>`;
}

function crmRenderActivityView(records) {
  const rows = crmWorkspace.activities.filter((activity) => activity.status !== "Done" && (!records.length || records.some((row) => String(row.id) === String(activity.recordId))));
  return `<section class="crm-activity-view"><header><div><h2>Planned Activities</h2><p>Follow-up actions due for your opportunities.</p></div><button type="button" class="action-button" data-crm-action="new-activity">Schedule Activity</button></header>${rows.length ? rows.map((activity) => `<article><span class="crm-activity-icon">◷</span><div><strong>${escapeHtml(activity.summary)}</strong><small>${escapeHtml(activity.recordName)} · ${escapeHtml(activity.assignedTo || "Unassigned")}</small></div><time>${escapeHtml(activity.dueDate || "No due date")}</time><button type="button" data-crm-action="activity-done" data-id="${crmEscapeAttr(activity.id)}">Mark Done</button></article>`).join("") : `<div class="crm-empty-state"><div>✓</div><h2>All caught up</h2><p>Scheduled follow-ups will appear here.</p></div>`}</section>`;
}

function crmRenderPartnerships() {
  const wins = crmWorkspace.opportunities.filter((row) => crmStageByName(row.stage)?.won);
  const commissionRate = (record) => {
    const plans = crmWorkspace.commissions.filter((plan) => plan.active !== false);
    const plan = plans.find((item) => item.level && item.level === record.memberLevel) || plans.find((item) => !item.level);
    return Number(plan?.rate || 0);
  };
  const commissionFor = (record) => Number(record.expectedRevenue || 0) * commissionRate(record) / 100;
  const rows = wins.map((row) => `<tr><td><button type="button" class="crm-table-link" data-crm-action="open-record" data-id="${crmEscapeAttr(row.id)}">${escapeHtml(row.name)}</button></td><td>${escapeHtml(row.company || row.contactName || "")}${row.memberLevel ? `<small>${escapeHtml(row.memberLevel)}</small>` : ""}</td><td>${crmFormatMoney(row.expectedRevenue, row.currency)}</td><td>${commissionRate(row).toFixed(2)}%</td><td>${crmFormatMoney(commissionFor(row), row.currency)}</td><td>${escapeHtml(row.quotationRef || "—")}</td></tr>`).join("");
  return `<section class="crm-report-page"><div class="crm-report-heading"><div><span class="crm-report-eyebrow">PARTNER PERFORMANCE</span><h1>Partnerships</h1><p>Partner attribution, won revenue, and estimated commissions.</p></div><button type="button" class="action-button secondary" data-module-page="crm-commission-plans">Commission Plans</button></div><div class="crm-report-kpis"><article><span>Active Members</span><strong>${crmWorkspace.levels.filter((item) => item.active !== false).length}</strong></article><article><span>Won Opportunities</span><strong>${wins.length}</strong></article><article><span>Won Revenue</span><strong>${crmFormatMoney(wins.reduce((sum, row) => sum + Number(row.expectedRevenue || 0), 0), "$")}</strong></article><article><span>Estimated Commission</span><strong>${crmFormatMoney(wins.reduce((sum, row) => sum + commissionFor(row), 0), "$")}</strong></article></div><div class="crm-data-table-wrap"><table class="crm-data-table"><thead><tr><th>Opportunity</th><th>Partner</th><th>Won Revenue</th><th>Rate</th><th>Commission</th><th>Linked Quotation</th></tr></thead><tbody>${rows || `<tr><td colspan="6"><div class="crm-empty-state"><h2>No won partner deals</h2><p>Won opportunities with a linked partner appear here.</p></div></td></tr>`}</tbody></table></div></section>`;
}

function crmRenderForecast(records) {
  const opportunities = records.filter((row) => !row.isLead);
  const groups = new Map();
  opportunities.forEach((row) => {
    const key = crmWorkspace.forecastGroupBy ? String(row.expectedClosing || "").slice(0, 7) || "none" : "all";
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(row);
  });
  if (!crmWorkspace.forecastGroupBy) groups.set("all", opportunities);
  const monthDate = (key) => new Date(`${key}-01T00:00:00`);
  let monthKeys = [...groups.keys()].filter((key) => key !== "none" && key !== "all").sort();
  if (crmWorkspace.forecastGroupBy) {
    const today = new Date(); today.setDate(1);
    const current = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, "0")}`;
    const first = monthKeys.length ? (monthKeys[0] < current ? monthKeys[0] : current) : current;
    const last = monthKeys.length ? (monthKeys.at(-1) > current ? monthKeys.at(-1) : current) : current;
    const start = monthDate(first); const finish = monthDate(last); const minimumFinish = monthDate(first); minimumFinish.setMonth(minimumFinish.getMonth() + 3);
    if (minimumFinish > finish) finish.setTime(minimumFinish.getTime());
    monthKeys = [];
    for (const cursor = new Date(start); cursor <= finish; cursor.setMonth(cursor.getMonth() + 1)) monthKeys.push(`${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, "0")}`);
  } else monthKeys = [];
  const column = (key, rows, label) => {
    const total = rows.reduce((sum, row) => sum + Number(row.expectedRevenue || 0), 0);
    const won = rows.filter((row) => crmStageByName(row.stage)?.won).reduce((sum, row) => sum + Number(row.expectedRevenue || 0), 0);
    const probable = rows.filter((row) => !crmStageByName(row.stage)?.won && Number(row.probability || 0) >= 50).reduce((sum, row) => sum + Number(row.expectedRevenue || 0), 0);
    const basis = total || rows.length || 1;
    const green = total ? won / basis * 100 : rows.filter((row) => crmStageByName(row.stage)?.won).length / basis * 100;
    const yellow = total ? probable / basis * 100 : rows.filter((row) => !crmStageByName(row.stage)?.won && Number(row.probability || 0) >= 50).length / basis * 100;
    const red = rows.length ? Math.max(0, 100 - green - yellow) : 0;
    const cards = rows.map((row) => {
      const card = crmOpportunityCard(row).replace('class="crm-opportunity-card"', 'class="crm-opportunity-card crm-forecast-card" data-forecast-revenue="' + Number(row.expectedRevenue || 0) + '" data-forecast-probability="' + Number(row.probability || 0) + '" data-forecast-won="' + Boolean(crmStageByName(row.stage)?.won) + '"');
      return crmStageByName(row.stage)?.won ? card.replace(/(<article\b[^>]*>)/, '$1<span class="crm-forecast-won-ribbon">WON</span>') : card;
    }).join("");
    const attrKey = key === "all" ? "" : key;
    return `<section class="crm-forecast-column" data-forecast-column="${crmEscapeAttr(attrKey)}"><header class="crm-forecast-column-header"><div class="crm-forecast-heading"><h2>${escapeHtml(label)}</h2>${key !== "none" && key !== "all" ? `<button type="button" class="crm-stage-add" data-crm-action="forecast-add" data-month="${crmEscapeAttr(key)}" aria-label="Add opportunity for ${crmEscapeAttr(label)}">+</button>` : ""}</div><div class="crm-forecast-totals"><div class="crm-forecast-progress"><i style="width:${green.toFixed(1)}%"></i><b style="width:${yellow.toFixed(1)}%"></b><em style="width:${red.toFixed(1)}%"></em></div><strong>${crmFormatMoney(total, rows[0]?.currency || "$")}</strong>${rows.length ? `<span>${rows.length}</span>` : `<span class="is-empty">0</span>`}</div></header><div class="crm-forecast-cards">${cards || `<div class="crm-forecast-empty"></div>`}</div></section>`;
  };
  const none = groups.get("none") || [];
  const columns = `${crmWorkspace.forecastGroupBy ? column("none", none, "None") : ""}${monthKeys.map((key) => column(key, groups.get(key) || [], monthDate(key).toLocaleDateString("en-US", { month: "long", year: "numeric" }))).join("")}${!crmWorkspace.forecastGroupBy ? column("all", opportunities, "All Opportunities") : ""}`;
  return `<div class="crm-pipeline-scroll crm-forecast-scroll"><div class="crm-pipeline-board crm-forecast-board">${columns}<button type="button" class="crm-add-stage-column" data-crm-action="forecast-next" aria-label="Show later months">»</button></div></div>`;
}

function crmRenderQuotations() {
  const rows = (state.salesOrders || []).filter((order) => !crmWorkspace.search.trim() || [order.ref, order.customer, order.salesperson, order.status, order.invoiceStatus].some((value) => String(value || "").toLowerCase().includes(crmWorkspace.search.trim().toLowerCase())));
  const body = rows.map((order) => `<tr data-crm-searchable="${crmEscapeAttr([order.ref, order.customer, order.salesperson, order.status, order.invoiceStatus].join(" ").toLowerCase())}"><td><input type="checkbox" data-crm-select="${crmEscapeAttr(order.id)}" aria-label="Select ${crmEscapeAttr(order.ref || "Quotation")}"></td><td><button type="button" class="crm-table-link" data-crm-action="open-quotation" data-id="${crmEscapeAttr(order.id)}">${escapeHtml(order.ref || "Quotation")}</button></td><td>${escapeHtml(order.orderDate || "—")}</td><td>${escapeHtml(order.deliveryDate || "—")}</td><td>${escapeHtml(order.customer || "—")}</td><td>${crmFormatMoney(order.total || 0, "৳")}</td><td><span class="crm-stage-badge">${escapeHtml(order.status || "Quotation")}</span></td><td>${escapeHtml(order.invoiceStatus || "Nothing to Invoice")}</td><td>◷</td></tr>`).join("");
  return `${crmAppToolbar("My Quotations", { simple: true, searchFilter: "My Quotations", viewNames: [["list", "list"]], newButton: `<button type="button" class="action-button" data-crm-action="new-quotation">New</button>` })}<div class="crm-data-table-wrap"><table class="crm-data-table"><thead><tr><th><input type="checkbox" data-crm-select-all aria-label="Select all quotations"></th><th>Number</th><th>Order Date</th><th>Promised Delivery</th><th>Customer</th><th>Total</th><th>Status</th><th>Invoice Status</th><th>Activities</th></tr></thead><tbody>${body || `<tr><td colspan="9"><div class="crm-empty-state"><div>▤</div><h2>No quotations yet</h2><p>Create a quotation and keep its status and customer details linked to CRM.</p><button type="button" class="action-button" data-crm-action="new-quotation">New</button></div></td></tr>`}</tbody></table></div><div class="crm-config-count">1-${rows.length} / ${rows.length}</div>`;
}

function crmRenderCustomers() {
  const contacts = (state.contacts || []).filter((contact) => contact.kind !== "supplier").sort((a, b) => String(a.name || "").localeCompare(String(b.name || "")));
  const query = crmWorkspace.search.trim().toLowerCase();
  const rows = contacts.filter((contact) => [contact.name, contact.email, contact.phone, contact.country, contact.city, contact.company].some((value) => String(value || "").toLowerCase().includes(query)));
  const body = rows.map((contact) => {
    const relatedRecords = crmWorkspace.opportunities.filter((record) => String(record.contactName || record.company || "").toLowerCase() === String(contact.name || "").toLowerCase() || String(record.company || "").toLowerCase() === String(contact.company || "").toLowerCase());
    const activityCount = crmWorkspace.activities.filter((activity) => relatedRecords.some((record) => String(record.id) === String(activity.recordId))).length;
    return `<tr data-crm-searchable="${crmEscapeAttr([contact.name, contact.email, contact.phone, contact.country, contact.city, contact.company].join(" ").toLowerCase())}"><td><input type="checkbox" data-crm-select="${crmEscapeAttr(contact.id)}" aria-label="Select ${crmEscapeAttr(contact.name)}"></td><td><button type="button" class="crm-table-link" data-open-contact="${crmEscapeAttr(contact.id)}">${escapeHtml(contact.name || "")}</button></td><td>${escapeHtml(contact.email || "")}</td><td>${escapeHtml(contact.phone || "")}</td><td>${activityCount || ""}</td><td>${escapeHtml(contact.country || "")}</td></tr>`;
  }).join("");
  return `${crmAppToolbar("Customers", { simple: true, searchFilter: "Customers", viewNames: [["list", "list"]], newButton: `<button type="button" class="action-button" data-crm-action="new-customer">New</button>` })}<div class="crm-data-table-wrap"><table class="crm-data-table"><thead><tr><th><input type="checkbox" data-crm-select-all aria-label="Select all customers"></th><th>Name</th><th>Email</th><th>Phone</th><th>Activities</th><th>Country</th></tr></thead><tbody>${body || `<tr><td colspan="6"><div class="crm-empty-state"><div>♙</div><h2>No customers found</h2><p>Customer and contact records shared with Sales appear here.</p><button type="button" class="action-button" data-crm-action="new-customer">New</button></div></td></tr>`}</tbody></table></div><div class="crm-config-count">1-${rows.length} / ${rows.length}</div>`;
}

function crmRenderAnalysis(records) {
  const metrics = crmWorkspace.stages.map((stage) => { const rows = records.filter((record) => record.stage === stage.name); return { name: stage.name, count: rows.length, revenue: rows.reduce((sum, record) => sum + Number(record.expectedRevenue || 0), 0), weighted: rows.reduce((sum, record) => sum + crmExpectedRevenue(record), 0) }; });
  const max = Math.max(1, ...metrics.map((item) => item.revenue));
  return `<section class="crm-report-page"><div class="crm-report-heading"><div><span class="crm-report-eyebrow">PIPELINE REPORTING</span><h1>Pipeline Analysis</h1><p>Review opportunity count, expected revenue, weighted revenue and conversion by stage.</p></div><button type="button" class="action-button secondary" data-crm-action="export">Export</button></div><div class="crm-analysis-bars">${metrics.map((item) => `<article><header><strong>${escapeHtml(item.name)}</strong><span>${item.count} opportunities · ${crmFormatMoney(item.revenue, "$")}</span></header><div><i style="width:${Math.max(2, item.revenue / max * 100)}%"></i></div><footer><span>Weighted ${crmFormatMoney(item.weighted, "$")}</span><span>Stage probability ${crmStageProbability(item.name)}%</span></footer></article>`).join("")}</div></section>`;
}

function crmTeamMembers(team) {
  const saved = Array.isArray(team.members) ? team.members : typeof team.members === "string" ? team.members.split(/[,;\n]/) : [];
  const members = [...new Set(saved.map((name) => String(name || "").trim()).filter(Boolean))];
  return members.length ? members : (team.leader ? [team.leader] : []);
}

function crmTeamSalespeople() {
  const names = [state.userProfile?.name, ...crmWorkspace.teams.map((team) => team.leader), ...crmWorkspace.opportunities.map((record) => record.salesperson)];
  return [...new Set(names.map((name) => String(name || "").trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b));
}

function crmTeamRecords() {
  const query = crmWorkspace.search.trim().toLowerCase();
  return [...crmWorkspace.teams].sort((a, b) => Number(a.sequence || 0) - Number(b.sequence || 0)).filter((team) => {
    if (crmWorkspace.teamStatusFilter === "Active" && team.active === false) return false;
    if (crmWorkspace.teamStatusFilter === "Archived" && team.active !== false) return false;
    const opportunityCount = crmWorkspace.opportunities.filter((record) => record.team === team.name).length;
    if (crmWorkspace.teamHasOpportunities === "Has Opportunities" && !opportunityCount) return false;
    if (crmWorkspace.teamHasOpportunities === "No Opportunities" && opportunityCount) return false;
    return !query || [team.name, team.alias, team.aliasDomain, team.leader, team.company].join(" ").toLowerCase().includes(query);
  });
}

function crmRenderTeamList() {
  if (crmWorkspace.teamEditor) return crmRenderTeamDetail();
  const teams = crmTeamRecords();
  const groupKey = crmWorkspace.teamGroupBy === "Company" ? "company" : crmWorkspace.teamGroupBy === "Team Leader" ? "leader" : "";
  let lastGroup = "";
  const rows = teams.map((team) => {
    const searchable = [team.name, team.alias, team.aliasDomain, team.leader, team.company].join(" ").toLowerCase();
    const count = crmWorkspace.opportunities.filter((record) => record.team === team.name).length;
    const groupValue = groupKey ? String(team[groupKey] || "Unassigned") : "";
    const groupRow = groupKey && groupValue !== lastGroup ? `<tr class="crm-team-group-row"><th colspan="5">${escapeHtml(groupValue)}</th></tr>` : "";
    lastGroup = groupValue;
    return `${groupRow}<tr data-crm-team-row="${crmEscapeAttr(team.id)}" data-crm-searchable="${crmEscapeAttr(searchable)}"><td class="crm-team-select-cell"><input type="checkbox" data-crm-select="${crmEscapeAttr(team.id)}" aria-label="Select ${crmEscapeAttr(team.name)}"></td><td class="crm-team-grip-cell"><button type="button" draggable="true" data-crm-team-drag="${crmEscapeAttr(team.id)}" aria-label="Reorder ${crmEscapeAttr(team.name)}" title="Drag to reorder"><svg viewBox="0 0 12 16" aria-hidden="true"><circle cx="3" cy="3" r="1.1"/><circle cx="9" cy="3" r="1.1"/><circle cx="3" cy="8" r="1.1"/><circle cx="9" cy="8" r="1.1"/><circle cx="3" cy="13" r="1.1"/><circle cx="9" cy="13" r="1.1"/></svg></button></td><td><button type="button" class="crm-table-link crm-team-name-link" data-crm-action="open-team" data-id="${crmEscapeAttr(team.id)}">${escapeHtml(team.name)}</button></td><td>${escapeHtml(team.alias || "")}</td><td>${team.leader ? `<span class="crm-team-avatar">${escapeHtml(team.leader.trim().split(/\s+/).map((part) => part[0]).slice(0, 2).join("").toUpperCase())}</span>${escapeHtml(team.leader)}` : ""}</td><td>${escapeHtml(team.company === "My Company (San Francisco)" ? "" : team.company || "")}</td></tr>`;
  }).join("");
  const selected = crmWorkspace.selectedIds.size;
  return `<section class="crm-config-page crm-team-list-page"><header class="crm-config-toolbar"><div><button type="button" class="action-button" data-crm-action="new-config" data-kind="crm-sales-teams">New</button><div><span class="crm-config-breadcrumb">CRM</span><h1>Sales Teams</h1></div><button type="button" class="crm-more-button" data-crm-action="toggle-dropdown" data-dropdown="config-more" aria-label="More actions">⋮</button></div><label class="crm-config-search">${icon("search")}<input data-crm-config-search type="search" value="${crmEscapeAttr(crmWorkspace.search)}" placeholder="Search..."></label><span class="crm-config-count">${teams.length ? `1-${teams.length} / ${teams.length}` : "0 / 0"}</span><div class="crm-team-list-tools"><button type="button" class="crm-team-filter-button" data-crm-action="team-filter-dropdown" aria-expanded="${Boolean(crmWorkspace.teamFilterDropdown)}" aria-label="Filters"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M7 12h10m-7 6h4M8 4v4m8 2v4m-5 2v4"/></svg></button>${crmWorkspace.teamFilterDropdown ? `<div class="crm-team-filter-menu"><strong>Filters</strong>${["All", "Active", "Archived"].map((filter) => `<button type="button" class="${crmWorkspace.teamStatusFilter === filter ? "is-current" : ""}" data-crm-action="team-status-filter" data-filter="${filter}">${crmWorkspace.teamStatusFilter === filter ? "✓" : ""} ${filter} Teams</button>`).join("")}<hr><strong>Opportunity Rules</strong>${["", "Has Opportunities", "No Opportunities"].map((filter) => `<button type="button" class="${crmWorkspace.teamHasOpportunities === filter ? "is-current" : ""}" data-crm-action="team-opportunity-filter" data-filter="${crmEscapeAttr(filter)}">${crmWorkspace.teamHasOpportunities === filter ? "✓" : ""} ${filter || "Any"}</button>`).join("")}<hr><strong>Group By</strong>${["", "Company", "Team Leader"].map((group) => `<button type="button" class="${crmWorkspace.teamGroupBy === group ? "is-current" : ""}" data-crm-action="team-group-by" data-group="${crmEscapeAttr(group)}">${crmWorkspace.teamGroupBy === group ? "✓" : ""} ${group || "None"}</button>`).join("")}</div>` : ""}</div></header>${selected ? `<div class="crm-team-selection"><span>${selected} selected</span><button type="button" data-crm-action="team-clear-selection">×</button></div>` : ""}<div class="crm-data-table-wrap"><table class="crm-data-table crm-config-table crm-team-table"><thead><tr><th><input type="checkbox" data-crm-select-all aria-label="Select all teams"></th><th class="crm-team-grip-heading"></th><th>Sales Team</th><th>Alias</th><th>Team Leader</th><th>Company</th></tr></thead><tbody>${rows || `<tr><td colspan="6"><div class="crm-empty-state"><div>♧</div><h2>No sales teams found</h2><p>Clear the search or filters, or add a new sales team.</p><button type="button" class="action-button" data-crm-action="new-config" data-kind="crm-sales-teams">New</button></div></td></tr>`}</tbody></table></div></section>`;
}

function crmRenderTeamDetail() {
  const editor = crmWorkspace.teamEditor;
  const team = crmWorkspace.teams.find((item) => String(item.id) === String(editor.id));
  if (!team && !editor.isNew) { crmWorkspace.teamEditor = null; return crmRenderTeamList(); }
  const record = team || { id: editor.id, name: "", alias: "", aliasDomain: "", acceptEmailsFrom: "Everyone", leader: state.userProfile?.name || "", company: "", members: [], active: true };
  const edit = Boolean(editor.editMode);
  const leaders = [...new Set([record.leader, ...crmTeamSalespeople()].filter(Boolean))];
  const companies = [...new Set([record.company, ...crmWorkspace.opportunities.map((item) => item.company).filter(Boolean)])].filter((company) => company !== "My Company (San Francisco)");
  const companyValue = record.company === "My Company (San Francisco)" ? "" : record.company;
  const opportunityCount = crmWorkspace.opportunities.filter((item) => item.team === record.name).length;
  const members = crmTeamMembers(record);
  const control = (key, label, value, options = null) => edit ? `<label class="crm-team-field"><span>${escapeHtml(label)}</span>${options ? `<select name="${key}">${options.map((option) => `<option value="${crmEscapeAttr(option)}" ${option === value ? "selected" : ""}>${escapeHtml(option || "—")}</option>`).join("")}</select>` : `<input name="${key}" value="${crmEscapeAttr(value || "")}" ${key === "name" ? "required" : ""}>`}</label>` : `<div class="crm-team-field"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value || (key === "company" ? "Visible to all" : "—"))}</strong></div>`;
  const aliasField = edit ? `<label class="crm-team-field"><span>Email Alias</span><div class="crm-team-alias-input"><input name="alias" value="${crmEscapeAttr(record.alias || "")}" placeholder="info"><span>@</span><input name="aliasDomain" value="${crmEscapeAttr(record.aliasDomain || "")}" placeholder="e.g. mycompany.com"></div></label>` : `<div class="crm-team-field"><span>Email Alias</span><strong>${escapeHtml(record.alias || "")}${record.aliasDomain ? ` @ ${escapeHtml(record.aliasDomain)}` : ""}</strong></div>`;
  const accepts = ["Everyone", "Authenticated Partners", "Followers only", "Employees only"];
  const acceptsField = edit ? `<label class="crm-team-field"><span>Accept Emails From</span><select name="acceptEmailsFrom">${accepts.map((option) => `<option ${option === (record.acceptEmailsFrom || "Everyone") ? "selected" : ""}>${escapeHtml(option)}</option>`).join("")}</select></label>` : `<div class="crm-team-field"><span>Accept Emails From</span><strong>${escapeHtml(record.acceptEmailsFrom || "Everyone")}</strong></div>`;
  const leaderField = edit ? control("leader", "Team Leader", record.leader, leaders) : control("leader", "Team Leader", record.leader);
  const companyField = edit ? control("company", "Company", companyValue, ["", ...companies]) : control("company", "Company", companyValue);
  const chatter = `<aside class="crm-team-chatter"><div class="crm-team-chatter-actions"><button type="button" class="action-button" data-crm-action="team-chatter" data-type="Send message">Send message</button><button type="button" data-crm-action="team-chatter" data-type="Log note">Log note</button><button type="button" data-crm-action="team-chatter" data-type="WhatsApp">WhatsApp</button></div><div class="crm-team-chatter-day">Today</div><article><span class="crm-team-avatar crm-team-bot">◉</span><div><small>OdooBot</small><p>${team ? `${escapeHtml(record.name)} team opened` : "Creating a new sales team..."}</p></div></article>${team ? `<article><span class="crm-team-avatar">${escapeHtml((record.leader || "M").split(/\s+/).map((part) => part[0]).slice(0, 2).join("").toUpperCase())}</span><div><small>${escapeHtml(record.leader || "Mitchell Admin")}</small><p>Sales Team updated</p></div></article>` : ""}</aside>`;
  return `<section class="crm-team-detail-page"><header class="crm-team-detail-toolbar"><div><button type="button" class="action-button" data-crm-action="team-back">Sales Teams</button><span>/</span><strong>${escapeHtml(record.name || "New")}</strong></div><div class="crm-team-detail-actions">${edit ? `<button type="button" class="action-button secondary" data-crm-action="team-discard">Discard</button><button type="submit" form="crm-team-form" class="action-button">Save</button>` : `<button type="button" class="action-button secondary" data-crm-action="team-edit">Edit</button><button type="button" class="action-button secondary" data-crm-action="team-archive">${record.active === false ? "Unarchive" : "Archive"}</button>`}</div></header><div class="crm-team-detail-body"><main class="crm-team-form-main">${window.ERPSharedSmartPanel.render({className:"crm-team-smartbar",items:[{icon:"★",label:"Opportunities",value:opportunityCount,className:"crm-team-smart-button",attributes:{"data-crm-action":"team-opportunities"}}]})}<div class="crm-team-record-card"><form id="crm-team-form" data-id="${crmEscapeAttr(record.id)}" data-is-new="${editor.isNew ? "1" : "0"}"><div class="crm-team-record-heading">${edit ? `<label class="crm-team-title-edit"><span>Sales Team</span><input name="name" value="${crmEscapeAttr(record.name)}" required placeholder="Sales Team"></label>` : `<span>Sales Team</span><h1>${escapeHtml(record.name)}</h1>`}</div><section class="crm-team-info"><header>TEAM DETAILS</header><div class="crm-team-fields">${leaderField}${aliasField}${acceptsField}${companyField}</div></section><nav class="crm-team-tabs"><button type="button" class="is-active">Members</button></nav><section class="crm-team-members-panel"><div class="crm-team-members-grid">${members.map((name) => `<article class="crm-team-member-card"><span class="crm-team-member-avatar">${escapeHtml(name.split(/\s+/).map((part) => part[0]).slice(0, 2).join("").toUpperCase())}</span><span><strong>${escapeHtml(name)}</strong><small>✉ ${escapeHtml(name === "Mitchell Admin" ? "admin@example.com" : name === "Marc Demo" ? "marc@example.com" : "")}</small></span></article>`).join("")}<button type="button" class="crm-team-add-members" data-crm-action="team-add-members"><span>＋</span> Add Salespersons</button></div></section></form></div></main>${chatter}</div></section>`;
}

function crmRenderConfigurationPage() {
  if (state.page === "crm-sales-teams") return crmRenderTeamList();
  const schema = crmSchemas[state.page];
  if (!schema) return crmRenderSettings();
  const records = crmWorkspace[schema.key] || [];
  const rows = records.map((record) => {
    const searchable = [record.name, ...schema.columns.map(([, key]) => record[key])].filter((value) => value !== undefined && value !== null).join(" ").toLowerCase();
    return `<tr data-crm-searchable="${crmEscapeAttr(searchable)}"><td><input type="checkbox" data-crm-select="${crmEscapeAttr(record.id)}" aria-label="Select ${crmEscapeAttr(record.name)}"></td>${schema.columns.map(([, key]) => `<td>${key === "active" || key === "won" ? `<span class="crm-boolean ${record[key] ? "is-on" : ""}">${record[key] ? "Yes" : "No"}</span>` : key === "probability" ? `${Number(record[key] || 0)}%` : key === "rate" ? `${Number(record[key] || 0).toFixed(2)}%` : escapeHtml(record[key] ?? "")}</td>`).join("")}<td><button type="button" class="crm-row-edit" data-crm-action="edit-config" data-kind="${state.page}" data-id="${crmEscapeAttr(record.id)}" aria-label="Edit ${crmEscapeAttr(record.name)}">✎</button><button type="button" class="crm-row-delete" data-crm-action="delete-config" data-kind="${state.page}" data-id="${crmEscapeAttr(record.id)}" aria-label="Delete ${crmEscapeAttr(record.name)}">×</button></td></tr>`;
  }).join("");
  return `<section class="crm-config-page"><header class="crm-config-toolbar"><div><button type="button" class="action-button" data-crm-action="new-config" data-kind="${state.page}">New</button><div><span class="crm-config-breadcrumb">Configuration</span><h1>${escapeHtml(schema.title)}</h1></div><button type="button" class="crm-more-button" data-crm-action="toggle-dropdown" data-dropdown="config-more" aria-label="More actions">⋮</button></div><label class="crm-config-search">${icon("search")}<input data-crm-config-search type="search" value="${crmEscapeAttr(crmWorkspace.search)}" placeholder="Search..."></label><span class="crm-config-count">1-${records.length} / ${records.length}</span></header><div class="crm-config-description">${escapeHtml(schema.description)}</div><div class="crm-data-table-wrap"><table class="crm-data-table crm-config-table"><thead><tr><th><input type="checkbox" data-crm-select-all aria-label="Select all"></th>${schema.columns.map(([label]) => `<th>${escapeHtml(label)}</th>`).join("")}<th></th></tr></thead><tbody>${rows || `<tr><td colspan="${schema.columns.length + 2}"><div class="crm-empty-state"><div>▤</div><h2>${escapeHtml(schema.title)}</h2><p>${escapeHtml(schema.description)}</p><button type="button" class="action-button" data-crm-action="new-config" data-kind="${state.page}">New</button></div></td></tr>`}</tbody></table></div></section>`;
}

function crmSettingToggle(key, label, description, control = "checkbox", extra = "") {
  const value = crmWorkspace.settings[key];
  const checked = Boolean(value);
  const dependent = extra ? `<div class="crm-setting-dependent" data-setting-dependent="${key}" ${checked ? "" : 'inert aria-disabled="true"'}>${extra}</div>` : "";
  return `<div class="crm-setting-row"><div class="crm-setting-main"><input name="${key}" type="${control}" data-crm-setting-toggle ${control === "radio" ? `value="${value}"` : checked ? "checked" : ""}><span><strong>${escapeHtml(label)}</strong><small>${escapeHtml(description)}</small>${dependent}</span></div></div>`;
}

function crmRenderSettingsContent() {
  const settings = crmWorkspace.settings;
  const scoringFields = (settings.probabilityFields || []).map((key) => crmProbabilityDimensions.find(([field]) => field === key)?.[1]).filter(Boolean);
  const scoringBasis = ["Stage", ...scoringFields].join(", ").replace(/, ([^,]*)$/, " and $1");
  const scoring = `<div class="crm-setting-row"><div class="crm-setting-main"><span class="crm-setting-empty-control"></span><span><strong>Predictive Lead Scoring</strong><small>The success rate is computed based on <b>${escapeHtml(scoringBasis)}</b> for the leads created as of ${escapeHtml(crmProbabilityDateLabel(settings.probabilityCutoffDate))}.</small><button type="button" class="action-button crm-probability-button" data-crm-action="update-probabilities">Update Probabilities</button></span></div></div>`;
  const memberSettings = crmSettingToggle("membership", "Membership / Partnership", "Manage members or partners. Members get levels and pricelists, partners allow lead assignment and commission plans.", "checkbox", `<label class="crm-inline-setting">Name given to affiliates <input name="memberLabel" value="${crmEscapeAttr(settings.memberLabel || "Members")}" maxlength="50"></label><div class="crm-setting-links"><button type="button" data-module-page="crm-levels">Levels</button><button type="button" data-module-page="crm-partner-activations">Partner Activations</button><button type="button" data-module-page="crm-commission-plans">Commission Plans</button></div>`);
  const enrichment = crmSettingToggle("leadEnrichment", "Lead Enrichment", "Enrich your leads with company data based on their email addresses.", "checkbox", `<div class="crm-enrichment-mode"><label><input type="radio" name="leadEnrichmentMode" value="manual" ${settings.leadEnrichmentMode === "manual" ? "checked" : ""}> Enrich leads on demand only</label><label><input type="radio" name="leadEnrichmentMode" value="automatic" ${settings.leadEnrichmentMode !== "manual" ? "checked" : ""}> Enrich all leads automatically</label><div class="crm-setting-links"><button type="button" data-crm-action="view-services">View My Services</button></div></div>`);
  const leadMining = crmSettingToggle("leadMining", "Lead Mining", "Generate new leads based on their country, industry, size, etc.", "checkbox", `<div class="crm-setting-links"><button type="button" data-module-page="crm-lead-mining-requests">Lead Mining Requests</button><button type="button" data-crm-action="view-services">View My Services</button></div>`);
  const visitsToLeads = crmSettingToggle("visitsToLeads", "Visits to Leads", "Convert visitors of your website into leads and perform data enrichment based on their IP address.", "checkbox", `<div class="crm-setting-links"><button type="button" data-module-page="crm-visits-to-leads-rules">Visits to Leads Rules</button><button type="button" data-crm-action="view-services">View My Services</button></div>`);
  const crmFields = `<div class="crm-settings-columns"><div class="crm-settings-column">${crmSettingToggle("recurringRevenues", "Recurring Revenues", "Define recurring plans and revenues on Opportunities.")}${crmSettingToggle("multiTeams", "Multi Teams", "Assign salespeople into multiple Sales Teams.")}${crmSettingToggle("partnerWebsite", "Partners Website Page", "Display your partners on a dedicated website page.", "checkbox", `<div class="crm-setting-links"><button type="button" data-crm-action="edit-partner-page">Edit Page</button></div>`)}${scoring}</div><div class="crm-settings-column">${crmSettingToggle("leads", "Leads", "Add a qualification step before the creation of an opportunity.")}${memberSettings}${crmSettingToggle("ruleBasedAssignment", "Rule-Based Assignment", "Periodically assign leads based on priorities and filters.")}</div></div>`;
  const generation = `<div class="crm-settings-columns"><div class="crm-settings-column">${enrichment}${visitsToLeads}</div><div class="crm-settings-column">${leadMining}</div></div>`;
  const commissions = `<div class="crm-settings-columns"><div class="crm-settings-column"><div class="crm-setting-row"><div class="crm-setting-main"><span class="crm-setting-empty-control"></span><span><strong>Automatic PO frequency</strong><small>Frequency at which purchase orders will be automatically confirmed.</small><select name="automaticPoFrequency"><option ${settings.automaticPoFrequency === "Monthly" ? "selected" : ""}>Monthly</option><option ${settings.automaticPoFrequency === "Quarterly" ? "selected" : ""}>Quarterly</option><option ${settings.automaticPoFrequency === "Yearly" ? "selected" : ""}>Yearly</option></select></span></div></div></div><div class="crm-settings-column"><div class="crm-setting-row"><div class="crm-setting-main"><span class="crm-setting-empty-control"></span><span><strong>Minimum PO amount total</strong><small>The required minimum amount total needed to automatically confirm purchase orders.</small><label class="crm-money-setting">$ <input type="number" name="minimumPoAmount" min="0" step="0.01" value="${Number(settings.minimumPoAmount || 0).toFixed(2)}"></label></span></div></div></div></div>`;
  return `<form id="crm-settings-form" class="crm-settings-form">${crmSettingsSection("CRM", crmFields, "crm")}${crmSettingsSection("Lead Generation", generation, "crm-lead-generation")}${crmSettingsSection("Partners Commissions", commissions, "crm-partner-commissions")}<p class="crm-local-note">CRM configuration and records are stored in this browser workspace. Live email enrichment, lead mining, website tracking, and automated purchase orders require a connected Odoo server and service credits.</p></form>`;
}

function crmRenderSettings() {
  return renderSettingsWorkspace("crm-settings", crmRenderSettingsContent(), { saveAction: "", discardAction: 'data-crm-action="discard-settings"', saveType: "submit", form: "crm-settings-form" });
}

function crmSettingsSection(title, content, block) {
  return `<section class="settings-section odoo-settings-section crm-settings-section" data-settings-block="${block}"><header>${escapeHtml(title)}</header><div class="settings-section-body odoo-settings-body">${content}</div></section>`;
}
function crmDisabledFeatureMessage(page) {
  const disabled = page === "crm-leads" && !crmWorkspace.settings.leads
    ? "Leads"
    : page === "crm-lead-mining-requests" && (!crmWorkspace.settings.leads || !crmWorkspace.settings.leadMining)
      ? "Lead Mining"
      : page === "crm-visits-to-leads-rules" && (!crmWorkspace.settings.leads || !crmWorkspace.settings.visitsToLeads)
        ? "Visits to Leads"
        : ["crm-partnerships", "crm-levels", "crm-partner-activations", "crm-commission-plans"].includes(page) && !crmWorkspace.settings.membership
          ? "Membership / Partnership"
          : "";
  return disabled ? `<section class="crm-disabled-feature"><h1>${escapeHtml(disabled)} is disabled</h1><p>Enable this feature from CRM Settings to use its pages and actions.</p><button type="button" class="action-button" data-module-page="crm-settings">Open CRM Settings</button></section>` : "";
}

function crmRenderPage() {
  if (state.page === "crm-settings") return crmRenderSettings();
  const inactiveFeature = crmDisabledFeatureMessage(state.page);
  if (inactiveFeature) return inactiveFeature;
  if (crmSchemas[state.page]) return crmRenderConfigurationPage();
  if (state.page === "crm-quotations") return crmRenderQuotations();
  if (state.page === "crm-customers") return crmRenderCustomers();
  if (state.page === "crm-forecast") {
    const today = inputLocalDate();
    const forecastRecords = crmVisibleRecords({ onlyOpportunities: true }).filter((row) => {
      if (crmWorkspace.forecastFilters.includes("Upcoming Closings") && (!row.expectedClosing || row.expectedClosing < today || crmStageByName(row.stage)?.won)) return false;
      if (crmWorkspace.forecastFilters.includes("My Opportunities") && row.isLead) return false;
      return true;
    });
    const views = { kanban: crmRenderForecast(forecastRecords), list: crmRenderList(forecastRecords), graph: crmRenderGraph(forecastRecords), pivot: crmRenderPivot(forecastRecords) };
    return crmAppToolbar("Forecast", { forecast: true, hideTitle: true, viewNames: [["kanban", "kanban"], ["graph", "chart"], ["pivot", "pivot"], ["list", "list"]] }) + (views[crmWorkspace.view] || views.kanban);
  }
  if (state.page === "crm-pipeline-analysis") return crmAppToolbar("Pipeline") + (crmWorkspace.view === "pivot" ? crmRenderPivot(crmVisibleRecords()) : crmRenderAnalysis(crmVisibleRecords()));
  if (state.page === "crm-partnerships") return crmAppToolbar("Partnerships") + crmRenderPartnerships();
  if (state.page === "crm-activities") {
    const records = crmVisibleRecords();
    const activities = crmWorkspace.activities.filter((item) => crmWorkspace.activityFilter === "All" || (crmWorkspace.activityFilter === "Planned" ? item.status !== "Done" : item.status === "Done"));
    return `${crmAppToolbar("My Activities", { simple: true, searchFilter: "My Activities", viewNames: [["list", "list"]], newButton: `<button type="button" class="action-button" data-crm-action="new-activity">New</button>` })}<section class="crm-activity-register"><header><div><h1>My Activities</h1><p>Follow-up actions scheduled from CRM leads and opportunities.</p></div><div><select data-crm-activity-filter><option ${crmWorkspace.activityFilter === "Planned" ? "selected" : ""}>Planned</option><option ${crmWorkspace.activityFilter === "Done" ? "selected" : ""}>Done</option><option ${crmWorkspace.activityFilter === "All" ? "selected" : ""}>All</option></select><button type="button" class="action-button" data-crm-action="new-activity">Schedule Activity</button></div></header>${activities.length ? `<div class="crm-data-table-wrap"><table class="crm-data-table"><thead><tr><th>Activity Type</th><th>Summary</th><th>Assigned to</th><th>Deadline</th><th>Opportunity</th><th>Status</th><th></th></tr></thead><tbody>${activities.map((item) => `<tr><td>${escapeHtml(item.type)}</td><td>${escapeHtml(item.summary)}</td><td>${escapeHtml(item.assignedTo || "")}</td><td>${escapeHtml(item.dueDate || "")}</td><td><button type="button" class="crm-table-link" data-crm-action="open-record" data-id="${crmEscapeAttr(item.recordId)}">${escapeHtml(item.recordName || records.find((row) => String(row.id) === String(item.recordId))?.name || "")}</button></td><td>${escapeHtml(item.status)}</td><td><button type="button" data-crm-action="activity-done" data-id="${crmEscapeAttr(item.id)}">${item.status === "Done" ? "Reopen" : "Mark Done"}</button></td></tr>`).join("")}</tbody></table></div>` : `<div class="crm-empty-state"><div>✓</div><h2>No activities scheduled</h2><p>Schedule a follow-up from an opportunity or create one here.</p><button type="button" class="action-button" data-crm-action="new-activity">Schedule Activity</button></div>`}</section>`;
  }
  if (state.page === "crm-leads") return `${crmAppToolbar("Leads", { newButton: `<button type="button" class="action-button" data-crm-action="new-lead">New</button>` })}${crmRenderList(crmVisibleRecords({ onlyLeads: true }), { leadsOnly: true })}`;
  const records = crmVisibleRecords();
  let body;
  if (crmWorkspace.view === "list") body = crmRenderList(records.filter((row) => !row.isLead));
  else if (crmWorkspace.view === "calendar") body = crmRenderCalendar(records.filter((row) => !row.isLead));
  else if (crmWorkspace.view === "pivot") body = crmRenderPivot(records.filter((row) => !row.isLead));
  else if (crmWorkspace.view === "graph") body = crmRenderGraph(records.filter((row) => !row.isLead));
  else if (crmWorkspace.view === "map") body = crmRenderMap(records.filter((row) => !row.isLead));
  else if (crmWorkspace.view === "activity") body = crmRenderActivityView(records.filter((row) => !row.isLead));
  else body = crmRenderKanban(records);
  return `${crmAppToolbar("Pipeline")}${body}`;
}

function crmOpenOpportunity(record = null, { isLead = false, stage = "New" } = {}) {
  const existing = record || null;
  if (!existing && isLead && !crmWorkspace.settings.leads) { showToast("Enable Leads in CRM Settings before creating a lead."); return; }
  if (existing) crmShowBusyIndicator("Opening opportunity…");
  const stages = crmWorkspace.stages.map((item) => `<option value="${crmEscapeAttr(item.name)}" ${item.name === (existing?.stage || stage) ? "selected" : ""}>${escapeHtml(item.name)}</option>`).join("");
  const teams = crmWorkspace.teams.filter((item) => item.active !== false).map((item) => `<option ${item.name === (existing?.team || crmWorkspace.teams[0]?.name) ? "selected" : ""}>${escapeHtml(item.name)}</option>`).join("");
  const contacts = crmCompanyNames().map((name) => `<option value="${crmEscapeAttr(name)}">`).join("");
  const title = existing?.name || (isLead ? "New Lead" : "New Opportunity");
  openModal(`<header class="crm-record-modal-header"><div><span>${existing?.isLead ? "LEAD" : isLead ? "NEW LEAD" : "OPPORTUNITY"}</span><h2>${escapeHtml(title)}</h2></div><button type="button" class="modal-close" data-crm-action="close-modal" aria-label="Close">×</button></header><form id="crm-opportunity-form" data-id="${crmEscapeAttr(existing?.id || "")}" data-currency="${crmEscapeAttr(existing?.currency || "৳")}"><div class="crm-record-modal-actions"><button type="button" class="action-button secondary" data-crm-action="schedule-activity" data-id="${crmEscapeAttr(existing?.id || "")}" ${existing ? "" : "disabled"}>Schedule Activity</button>${existing?.isLead ? `<button type="button" class="action-button secondary" data-crm-action="convert-opportunity" data-id="${crmEscapeAttr(existing.id)}">Convert to Opportunity</button>` : `<button type="button" class="action-button secondary" data-crm-action="create-quotation" data-id="${crmEscapeAttr(existing?.id || "")}" ${existing ? "" : "disabled"}>${existing?.quotationRef ? `Open ${escapeHtml(existing.quotationRef)}` : "New Quotation"}</button>`}<button type="submit" class="action-button">Save</button></div><div class="crm-record-modal-body"><section class="crm-opportunity-form-main"><label class="crm-record-name-field"><span>${isLead || existing?.isLead ? "Lead Title" : "Opportunity"}</span><input name="name" maxlength="120" required value="${crmEscapeAttr(existing?.name || "")}" placeholder="e.g. New office project"></label><div class="crm-opportunity-form-grid"><label><span>Customer</span><input name="contactName" list="crm-contact-options" value="${crmEscapeAttr(existing?.contactName || "")}" placeholder="Contact or company"><datalist id="crm-contact-options">${contacts}</datalist></label><label><span>Company</span><input name="company" value="${crmEscapeAttr(existing?.company || "")}" placeholder="Company name"></label><label><span>Email</span><input type="email" name="email" value="${crmEscapeAttr(existing?.email || "")}" placeholder="name@company.com"></label><label><span>Phone</span><input type="tel" name="phone" value="${crmEscapeAttr(existing?.phone || "")}" placeholder="Phone number"></label><label><span>Expected Revenue</span><input type="number" name="expectedRevenue" min="0" step="0.01" value="${Number(existing?.expectedRevenue || 0)}"></label><label><span>Recurring Revenue</span><input type="number" name="recurringRevenue" min="0" step="0.01" value="${Number(existing?.recurringRevenue || 0)}" ${crmWorkspace.settings.recurringRevenues ? "" : "disabled"}></label><label><span>Probability</span><input type="number" name="probability" min="0" max="100" step="1" value="${Number(existing?.probability ?? crmStageProbability(existing?.stage || stage))}"></label><label><span>Expected Closing</span><input type="date" name="expectedClosing" value="${crmEscapeAttr(existing?.expectedClosing || inputLocalDate(new Date(Date.now() + 30 * 86400000)))}"></label><label><span>Salesperson</span><input name="salesperson" value="${crmEscapeAttr(existing?.salesperson || state.userProfile?.name || "")}" placeholder="Assign salesperson"></label><label><span>Sales Team</span><select name="team">${teams}</select></label><label><span>Stage</span><select name="stage">${stages}</select></label><label><span>Source</span><select name="source">${["Website", "Referral", "Phone", "Email", "Lead Mining", "Other"].map((item) => `<option ${item === (existing?.source || "Website") ? "selected" : ""}>${item}</option>`).join("")}</select></label><label><span>Country</span><input name="country" value="${crmEscapeAttr(existing?.country || "Bangladesh")}" placeholder="Country"></label><label><span>State / City</span><input name="state" value="${crmEscapeAttr(existing?.state || "")}" placeholder="State or city"></label><label><span>Language</span><input name="language" value="${crmEscapeAttr(existing?.language || "")}" placeholder="e.g. English"></label><label><span>Priority</span><select name="priority">${[0, 1, 2, 3].map((value) => `<option value="${value}" ${Number(existing?.priority || 0) === value ? "selected" : ""}>${"★".repeat(value) || "No priority"}</option>`).join("")}</select></label><label class="crm-field-wide"><span>Tags</span><input name="tags" value="${crmEscapeAttr((existing?.tags || []).join(", "))}" placeholder="Product, Consulting"></label><label class="crm-field-wide"><span>Internal Notes</span><textarea name="notes" rows="3" placeholder="Notes for your team">${escapeHtml(existing?.notes || "")}</textarea></label></div><footer class="crm-record-form-footer"><span>Lead Score <b>${crmLeadScore(existing || { stage, source: "Website", tags: [] })}/100</b></span><button type="button" class="crm-text-action" data-crm-action="enrich-record" data-id="${crmEscapeAttr(existing?.id || "")}" ${existing ? "" : "disabled"}>Enrich Lead</button><button type="button" class="crm-text-action crm-lost-action" data-crm-action="mark-lost" data-id="${crmEscapeAttr(existing?.id || "")}" ${existing ? "" : "disabled"}>Mark Lost</button></footer></section><aside class="crm-record-chatter"><div class="crm-chatter-heading"><h3>Chatter</h3><button type="button" data-crm-action="schedule-activity" data-id="${crmEscapeAttr(existing?.id || "")}" ${existing ? "" : "disabled"}>＋ Activity</button></div><p class="crm-chatter-subtitle">Messages, notes and scheduled follow-ups.</p><article class="crm-chatter-entry">${crmAvatar(state.userProfile?.name)}<div><strong>${escapeHtml(state.userProfile?.name || "Current User")}</strong><small>${existing ? `${escapeHtml(existing.stage || "New")} · ${escapeHtml(existing.name)}` : "Creating a new record"}</small>${existing?.notes ? `<p>${escapeHtml(existing.notes)}</p>` : ""}</div></article>${(existing ? crmWorkspace.activities.filter((item) => String(item.recordId) === String(existing.id)).slice(-4) : []).map((item) => `<article class="crm-chatter-entry"><span class="crm-chatter-activity-icon">◷</span><div><strong>${escapeHtml(item.type)} · ${escapeHtml(item.summary)}</strong><small>${escapeHtml(item.dueDate || "")}</small></div></article>`).join("")}<button type="button" class="crm-chatter-note-button" data-crm-action="schedule-activity" data-id="${crmEscapeAttr(existing?.id || "")}" ${existing ? "" : "disabled"}>Schedule an activity</button></aside></div></form>`, false);
  if (!crmWorkspace.settings.recurringRevenues) modalRoot.querySelector('#crm-opportunity-form [name="recurringRevenue"]')?.closest("label")?.remove();
  if (!crmWorkspace.settings.leadEnrichment || !existing?.isLead) modalRoot.querySelector('#crm-opportunity-form [data-crm-action="enrich-record"]')?.remove();
  if (!existing && crmWorkspace.settings.ruleBasedAssignment) {
    const salespersonField = modalRoot.querySelector('#crm-opportunity-form [name="salesperson"]');
    if (salespersonField) salespersonField.value = "";
  }
  if (crmWorkspace.settings.membership) {
    const companyField = modalRoot.querySelector('#crm-opportunity-form [name="company"]')?.closest("label");
    const levelOptions = crmWorkspace.levels.filter((level) => level.active !== false).map((level) => `<option value="${crmEscapeAttr(level.name)}" ${level.name === (existing?.memberLevel || "") ? "selected" : ""}>${escapeHtml(level.name)}</option>`).join("");
    companyField?.insertAdjacentHTML("afterend", `<label><span>Member Level</span><select name="memberLevel"><option value="">No member level</option>${levelOptions}</select></label>`);
  }
  modalRoot.querySelector(".modal-card")?.classList.add("crm-record-modal");
  const probabilityInput = modalRoot.querySelector('#crm-opportunity-form [name="probability"]');
  if (probabilityInput) {
    const probabilityMode = existing?.probabilityMode === "ai" ? "ai" : "manual";
    const stageName = existing?.stage || stage;
    const manualProbability = Number(existing?.manualProbability ?? existing?.probability ?? crmStageProbability(stageName));
    probabilityInput.closest("label")?.classList.add("crm-ai-probability-field");
    probabilityInput.closest("label")?.insertAdjacentHTML("beforeend", `<div class="crm-ai-probability-meta"><button type="button" class="crm-ai-probability-toggle" data-crm-action="toggle-ai-probability" aria-label="${probabilityMode === "ai" ? "Use manual probability" : "Use AI-calculated probability"}" aria-pressed="${probabilityMode === "ai"}" title="Toggle AI-calculated probability">AI</button><small class="crm-ai-probability-estimate"></small></div><input type="hidden" name="probabilityMode" value="${probabilityMode}"><input type="hidden" name="manualProbability" value="${manualProbability}">`);
    probabilityInput.disabled = probabilityMode === "ai";
    if (probabilityMode === "ai") probabilityInput.value = String(crmComputeProbability(existing, crmWorkspace.settings.probabilityFields));
    crmUpdateAiProbabilityPreview(modalRoot.querySelector("#crm-opportunity-form"));
  }
}

function crmProbabilityRecordFromForm(form) {
  const data = new FormData(form);
  return {
    stage: String(data.get("stage") || "New"), phone: String(data.get("phone") || ""), email: String(data.get("email") || ""),
    source: String(data.get("source") || "Other"), country: String(data.get("country") || ""), state: String(data.get("state") || ""),
    language: String(data.get("language") || ""), tags: String(data.get("tags") || "").split(/[,;]/).map((item) => item.trim()).filter(Boolean),
  };
}

function crmUpdateAiProbabilityPreview(form) {
  if (!form) return 0;
  const estimate = crmComputeProbability(crmProbabilityRecordFromForm(form), crmWorkspace.settings.probabilityFields);
  const label = form.querySelector(".crm-ai-probability-estimate");
  if (label) label.textContent = `~ ${estimate}%`;
  if (form.querySelector('[name="probabilityMode"]')?.value === "ai") {
    const input = form.querySelector('[name="probability"]');
    if (input) input.value = String(estimate);
  }
  return estimate;
}

function crmOpenConfigForm(kind, record = null) {
  if (kind === "crm-sales-teams") {
    const id = record?.id || crmId("crm-team");
    crmWorkspace.teamEditor = { id, isNew: !record, editMode: true };
    render();
    pageContent.querySelector('#crm-team-form [name="name"]')?.focus();
    return;
  }
  const schema = crmSchemas[kind];
  if (!schema) return;
  const fields = schema.fields.map(([key, label, type, optionsOrRequired]) => {
    const required = optionsOrRequired === true;
    const options = Array.isArray(optionsOrRequired) ? optionsOrRequired : null;
    let input;
    if (type === "checkbox") input = `<input type="checkbox" name="${key}" ${record ? (record[key] ? "checked" : "") : key === "active" ? "checked" : ""}>`;
    else if (type === "select" && options) input = `<select name="${key}">${options.map((option) => `<option ${String(record?.[key] || "") === option ? "selected" : ""}>${escapeHtml(option)}</option>`).join("")}</select>`;
    else if (type === "select" && optionsOrRequired === "levels") input = `<select name="${key}"><option value="">All levels</option>${crmWorkspace.levels.map((level) => `<option ${record?.[key] === level.name ? "selected" : ""}>${escapeHtml(level.name)}</option>`).join("")}</select>`;
    else input = `<input type="${type === "color" ? "color" : type === "number" ? "number" : "text"}" name="${key}" ${required ? "required" : ""} ${type === "number" ? 'min="0" step="any"' : ""} value="${crmEscapeAttr(record?.[key] ?? (key === "active" ? true : ""))}">`;
    return `<label class="crm-config-field ${type === "checkbox" ? "crm-config-checkbox" : ""}"><span>${escapeHtml(label)}</span>${input}</label>`;
  }).join("");
  openModal(`<header class="modal-header"><div><h2>${record ? "Edit" : "New"} ${escapeHtml(schema.title)}</h2><p>${escapeHtml(schema.description)}</p></div><button type="button" class="modal-close" data-crm-action="close-modal" aria-label="Close">×</button></header><form id="crm-config-form" data-kind="${kind}" data-id="${crmEscapeAttr(record?.id || "")}"><div class="modal-body crm-config-form-grid">${fields}</div><footer class="modal-footer"><button type="button" class="action-button secondary" data-crm-action="close-modal">Discard</button><button type="submit" class="action-button">Save</button></footer></form>`);
}

function crmOpenTeamMembers(team) {
  const current = new Set(crmTeamMembers(team));
  const people = [...new Set([...crmTeamSalespeople(), ...current])].sort((a, b) => a.localeCompare(b));
  openModal(`<header class="modal-header"><div><h2>Add Salespersons</h2><p>Choose the people who belong to ${escapeHtml(team.name)}.</p></div><button type="button" class="modal-close" data-crm-action="close-modal" aria-label="Close">×</button></header><form id="crm-team-members-form" data-id="${crmEscapeAttr(team.id)}"><div class="modal-body crm-team-member-picker">${people.map((name) => `<label><input type="checkbox" name="members" value="${crmEscapeAttr(name)}" ${current.has(name) ? "checked" : ""}><span class="crm-team-avatar">${escapeHtml(name.split(/\s+/).map((part) => part[0]).slice(0, 2).join("").toUpperCase())}</span><strong>${escapeHtml(name)}</strong></label>`).join("") || `<p>No salespeople are available yet.</p>`}</div><footer class="modal-footer"><button type="button" class="action-button secondary" data-crm-action="close-modal">Discard</button><button type="submit" class="action-button">Save</button></footer></form>`);
  if (!crmWorkspace.settings.multiTeams) modalRoot.querySelectorAll('#crm-team-members-form input[name="members"]').forEach((input) => {
    const assignedTeam = crmWorkspace.teams.find((item) => String(item.id) !== String(team.id) && crmTeamMembers(item).includes(input.value));
    if (assignedTeam && !current.has(input.value)) { input.disabled = true; input.closest("label")?.setAttribute("title", `Already assigned to ${assignedTeam.name}. Enable Multi Teams in CRM Settings to add them here.`); }
  });
}

function crmOpenActivityForm(record = null) {
  const types = crmWorkspace.activityTypes.filter((item) => item.active !== false).map((item) => `<option ${item.name === (record?.type || "To-Do") ? "selected" : ""}>${escapeHtml(item.name)}</option>`).join("");
  const opportunities = crmWorkspace.opportunities.filter((item) => !item.lost).map((item) => `<option value="${crmEscapeAttr(item.id)}" ${String(item.id) === String(record?.id || crmWorkspace.activeRecordId || "") ? "selected" : ""}>${escapeHtml(item.name)}</option>`).join("");
  openModal(`<header class="modal-header"><div><h2>Schedule Activity</h2><p>Set a follow-up on a lead or opportunity.</p></div><button type="button" class="modal-close" data-crm-action="close-modal">×</button></header><form id="crm-activity-form"><div class="modal-body crm-config-form-grid"><label class="crm-config-field"><span>Activity Type</span><select name="type">${types}</select></label><label class="crm-config-field"><span>Summary</span><input name="summary" required value="${crmEscapeAttr(record?.summary || "")}" placeholder="Call customer"></label><label class="crm-config-field"><span>Due Date</span><input name="dueDate" type="date" value="${crmEscapeAttr(record?.dueDate || inputLocalDate())}"></label><label class="crm-config-field"><span>Assigned to</span><input name="assignedTo" value="${crmEscapeAttr(record?.assignedTo || state.userProfile?.name || "")}"></label><label class="crm-config-field crm-field-wide"><span>Opportunity</span><select name="recordId" required>${opportunities}</select></label><label class="crm-config-field crm-field-wide"><span>Note</span><textarea name="note" rows="3" placeholder="Activity details">${escapeHtml(record?.note || "")}</textarea></label></div><footer class="modal-footer"><button type="button" class="action-button secondary" data-crm-action="close-modal">Discard</button><button type="submit" class="action-button">Schedule</button></footer></form>`);
}

function crmOpenLostForm(id) {
  openModal(`<header class="modal-header"><div><h2>Mark Opportunity Lost</h2><p>Select the reason to keep pipeline reporting accurate.</p></div><button type="button" class="modal-close" data-crm-action="close-modal">×</button></header><form id="crm-lost-form" data-id="${crmEscapeAttr(id)}"><div class="modal-body"><label class="crm-config-field"><span>Lost Reason</span><select name="reason" required>${crmWorkspace.lostReasons.filter((item) => item.active !== false).map((reason) => `<option>${escapeHtml(reason.name)}</option>`).join("")}</select></label></div><footer class="modal-footer"><button type="button" class="action-button secondary" data-crm-action="close-modal">Discard</button><button type="submit" class="action-button">Mark Lost</button></footer></form>`);
}

function crmOpenMiningForm() { crmOpenConfigForm("crm-lead-mining-requests"); }

function crmCurrentRecord(id) { return crmWorkspace.opportunities.find((item) => String(item.id) === String(id)); }

function crmPersistOpportunity(record, success = "Opportunity saved.") {
  const previous = crmCurrentRecord(record.id);
  const previousSnapshot = previous ? { ...previous } : null;
  const wasWon = Boolean(previousSnapshot && crmStageByName(previousSnapshot.stage)?.won);
  if (!previous && !record.createdAt) record.createdAt = inputLocalDate();
  if (previous) Object.assign(previous, record);
  else crmWorkspace.opportunities.unshift(record);
  if (!crmPersist("opportunities")) {
    if (previous) {
      Object.keys(previous).forEach((key) => { if (!Object.prototype.hasOwnProperty.call(previousSnapshot, key)) delete previous[key]; });
      Object.assign(previous, previousSnapshot);
    }
    else crmWorkspace.opportunities = crmWorkspace.opportunities.filter((item) => String(item.id) !== String(record.id));
    return false;
  }
  closeModal(); render(); showToast(success);
  if (!wasWon && crmStageByName(record.stage)?.won) crmShowWinCelebration(record);
  return true;
}

function crmShowBusyIndicator(message = "Opening opportunity…", duration = 520) {
  const region = document.querySelector(".toast-region");
  if (!region) return;
  const toast = document.createElement("div");
  toast.className = "toast crm-busy-toast";
  toast.setAttribute("role", "status");
  toast.innerHTML = `<span class="crm-spinner" aria-hidden="true"></span><span>${escapeHtml(message)}</span>`;
  region.append(toast);
  window.setTimeout(() => toast.remove(), duration);
}

function crmShowWinCelebration(record) {
  const celebration = document.createElement("div");
  celebration.className = "crm-win-celebration";
  celebration.setAttribute("role", "status");
  celebration.setAttribute("aria-live", "polite");
  celebration.innerHTML = `<div class="crm-win-art" aria-hidden="true"><svg viewBox="0 0 240 170"><path d="M30 115 A90 90 0 0 1 210 115" fill="none" stroke="#d7465c" stroke-width="12" stroke-linecap="round"/><path d="M42 115 A78 78 0 0 1 198 115" fill="none" stroke="#f2a33a" stroke-width="12" stroke-linecap="round"/><path d="M54 115 A66 66 0 0 1 186 115" fill="none" stroke="#f1d253" stroke-width="12" stroke-linecap="round"/><path d="M66 115 A54 54 0 0 1 174 115" fill="none" stroke="#42b78d" stroke-width="12" stroke-linecap="round"/><path d="M78 115 A42 42 0 0 1 162 115" fill="none" stroke="#46a9d5" stroke-width="12" stroke-linecap="round"/><circle cx="120" cy="116" r="39" fill="#fff" stroke="#d5dce0" stroke-width="4"/><path d="M103 111q5-8 10 0M128 111q5-8 10 0M105 125q15 15 30 0" fill="none" stroke="#485b67" stroke-width="4" stroke-linecap="round"/><path d="M161 123v-18q0-8 7-8t7 8v8l8-5q8-5 12 2l-7 21q-2 7-9 7h-15q-7 0-7-7v-8" fill="#fff" stroke="#627783" stroke-width="4" stroke-linejoin="round"/><path d="m36 69 5 9 10 1-7 7 2 10-10-5-9 5 2-10-8-7 11-1zM200 56l4 7 8 1-6 6 2 8-8-4-7 4 1-8-6-6 8-1zM51 143l3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1z" fill="#f1c64c"/></svg></div><strong>Opportunity won!</strong><span>${escapeHtml(record.name || "Great work")}</span>`;
  document.body.append(celebration);
  window.setTimeout(() => celebration.remove(), 2200);
}

function crmApplyAssignment(record) {
  if (!crmWorkspace.settings.ruleBasedAssignment) return record;
  if (!record.team) record.team = crmWorkspace.settings.defaultTeam || crmWorkspace.teams[0]?.name || "Sales";
  if (!record.salesperson) {
    const team = crmWorkspace.teams.find((item) => item.name === record.team);
    record.salesperson = crmWorkspace.settings.defaultSalesperson || team?.leader || crmTeamMembers(team || {}).find(Boolean) || "";
  }
  return record;
}

function crmCreateQuotation(id) {
  const record = crmCurrentRecord(id);
  if (!record) { showToast("Save the opportunity before creating a quotation."); return; }
  if (record.quotationId) {
    const order = state.salesOrders.find((item) => String(item.id) === String(record.quotationId));
    closeModal(); setPage(order?.status === "Sales Order" ? "sales-orders" : "sales-quotations");
    if (order) openSalesOrderModal(order.id);
    else showToast("The linked quotation is no longer in the Sales register.");
    return;
  }
  closeModal();
  const customer = record.company || record.contactName;
  setPage("sales-quotations");
  openSalesOrderModal(null, { customer, customerId: record.customerId || null, contactId: record.contactId || null, crmOpportunityId: record.id });
}

function crmConvertToCustomer(id) {
  const record = crmCurrentRecord(id);
  if (!record) return;
  const name = String(record.company || record.contactName || "").trim();
  if (!name) { showToast("Add the company or contact name before converting this lead to a customer."); return; }
  const existingContact = state.contacts.find((item) => String(item.name || "").toLowerCase() === name.toLowerCase());
  if (existingContact) {
    if (!(window.contactHasCustomerRole ? contactHasCustomerRole(existingContact) : existingContact.kind === "dealer") && !persistPartnerContact({ ...existingContact, kind: existingContact.kind === "contact" ? "dealer" : existingContact.kind, isCustomer: true, dealerType: existingContact.dealerType || "Dealer" })) return;
  } else {
    const linked = persistPartnerContact({ id: Date.now(), kind: "dealer", name, email: record.email || "", phone: record.phone || "", area: record.state || "", city: record.state || "", country: record.country || "Bangladesh", dealerType: "Dealer", creditLimit: 0, taxId: "", tags: (record.tags || []).join(", "), notes: "Converted from CRM opportunity.", address: "", isCustomer: true, isSupplier: false });
    if (!linked) return;
  }
  record.customerLinked = true;
  const linkedContact = state.contacts.find((item) => (window.contactHasCustomerRole ? contactHasCustomerRole(item) : item.kind === "dealer") && String(item.name).toLowerCase() === name.toLowerCase());
  record.contactId = linkedContact?.id || null;
  record.customerId = state.salesCustomers.find((item) => String(item.name || "").toLowerCase() === name.toLowerCase())?.id || null;
  if (!crmPersist("opportunities")) return;
  closeModal(); render(); showToast(`${name} is now linked to shared Contacts and Sales Customers.`);
}

function crmSalesOrderSaved(order) {
  const record = crmCurrentRecord(order?.crmOpportunityId);
  if (!record) return;
  record.quotationId = order.id;
  record.quotationRef = order.ref;
  if (order.status === "Sales Order") {
    record.stage = crmWorkspace.stages.find((stage) => stage.won)?.name || "Won";
    record.probability = 100;
  }
  crmPersist("opportunities");
}

function crmProbabilityDateLabel(value) {
  if (!value) return new Date().toLocaleDateString("en-US", { month: "short", day: "numeric" });
  const date = new Date(`${value}T00:00:00`);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString("en-US", { month: "short", day: "numeric" });
}

function crmRenderProbabilityModal() {
  const draft = crmWorkspace.probabilityDraft;
  if (!draft) return;
  const chosen = new Set(draft.fields);
  const colors = ["blue", "orange", "violet", "pink", "cyan", "red", "slate"];
  const chips = draft.fields.map((key) => {
    const index = crmProbabilityDimensions.findIndex(([field]) => field === key);
    const label = crmProbabilityDimensions[index]?.[1] || key;
    return `<button type="button" class="crm-probability-chip crm-probability-chip-${colors[index % colors.length]}" data-crm-action="remove-probability-dimension" data-field="${crmEscapeAttr(key)}" aria-label="Remove ${crmEscapeAttr(label)}">${escapeHtml(label)} <span aria-hidden="true">×</span></button>`;
  }).join("");
  const options = crmProbabilityDimensions.filter(([key]) => !chosen.has(key)).map(([key, label]) => `<option value="${key}">${escapeHtml(label)}</option>`).join("");
  const hiddenFields = draft.fields.map((key) => `<input type="hidden" name="probabilityFields" value="${crmEscapeAttr(key)}">`).join("");
  openModal(`<form id="crm-probability-form"><header class="modal-header"><h2>Update Probabilities</h2><button type="button" class="modal-close" data-crm-action="close-modal" aria-label="Close">×</button></header><div class="modal-body crm-probability-modal-body"><p>The success rate is computed based on the stage, but you can add more fields in the statistical analysis.</p><div class="crm-probability-field-row"><div class="crm-probability-chips" aria-label="Selected analysis fields">${chips}</div><label class="crm-probability-add"><span class="sr-only">Add an analysis field</span><select name="addProbabilityField" data-crm-probability-field aria-label="Add an analysis field"><option value="">Add a field…</option>${options}</select></label></div><label class="crm-probability-cutoff">Consider leads created as of the: <input type="date" name="probabilityCutoffDate" required value="${crmEscapeAttr(draft.cutoffDate)}"></label>${hiddenFields}</div><footer class="modal-footer crm-probability-modal-footer"><button type="submit" class="action-button">Update</button><button type="button" class="action-button secondary" data-crm-action="close-modal">Discard</button></footer></form>`);
  modalRoot.querySelector(".modal-card")?.classList.add("crm-probability-modal");
}

function crmOpenProbabilityModal() {
  const configuredFields = Array.isArray(crmWorkspace.settings.probabilityFields) ? crmWorkspace.settings.probabilityFields : crmDefaultSettings.probabilityFields;
  crmWorkspace.probabilityDraft = {
    fields: configuredFields.filter((key) => crmProbabilityDimensions.some(([field]) => field === key)),
    cutoffDate: crmWorkspace.settings.probabilityCutoffDate || inputLocalDate(),
  };
  crmRenderProbabilityModal();
}

function crmUpdateProbabilities(dimensions, cutoffDate) {
  const eligible = (record) => {
    if (record.lost || crmStageByName(record.stage)?.won) return false;
    const created = String(record.createdAt || record.createdDate || record.created_at || "").slice(0, 10);
    return !created || created <= cutoffDate;
  };
  let updated = 0;
  crmWorkspace.opportunities.forEach((record) => {
    if (eligible(record)) { record.probability = crmComputeProbability(record, dimensions); updated += 1; }
  });
  if (!crmPersist("opportunities")) return;
  crmWorkspace.settings.probabilityFields = [...dimensions];
  crmWorkspace.settings.probabilityCutoffDate = cutoffDate;
  if (!crmPersistSettings()) return;
  crmWorkspace.probabilityDraft = null;
  closeModal(); render(); showToast(`Updated success probabilities for ${updated} leads and opportunities.`);
}

function crmEnrichRecord(id) {
  const record = crmCurrentRecord(id);
  if (!record) { showToast("Save the lead before requesting enrichment."); return; }
  if (!crmWorkspace.settings.leadEnrichment) { showToast("Enable Lead Enrichment in CRM Settings first."); return; }
  if (crmEnrichFromSharedContact(record)) {
    if (!crmPersist("opportunities")) return;
    crmOpenOpportunity(record);
    showToast("Lead enriched from the shared Contacts directory.");
  } else {
    showToast("No matching shared contact was found. External enrichment requires connected Odoo IAP credits.");
  }
}

function crmExportRecords() {
  const rows = crmVisibleRecords().map((row) => [row.name, row.contactName, row.company, row.email, row.phone, row.salesperson, row.team, row.stage, row.expectedRevenue, row.probability, row.expectedClosing, row.source, (row.tags || []).join(", ")]);
  downloadCsv("crm-pipeline.csv", [["Opportunity", "Contact", "Company", "Email", "Phone", "Salesperson", "Team", "Stage", "Expected Revenue", "Probability", "Expected Closing", "Source", "Tags"], ...rows]);
}

function crmRenderNewConfigForm(kind) {
  const schema = crmSchemas[kind];
  if (!schema) return;
  if (kind === "crm-lead-mining-requests") {
    const number = crmWorkspace.miningRequests.length + 1;
    crmOpenConfigForm(kind, { name: `REQ${String(number).padStart(4, "0")}`, count: 100, countries: "United States", industries: "Furniture", status: "Draft" });
  } else crmOpenConfigForm(kind);
}

function crmHandleClick(event) {
  const actionNode = event.target.closest("[data-crm-action]");
  const genericAction = event.target.closest("[data-action]");
  if (!actionNode && !(state.module === "crm" && genericAction)) return;
  if (state.module !== "crm" && !actionNode) return;
  const action = actionNode?.dataset.crmAction;
  if (action === "close-modal") { closeModal(); return; }
  if (action === "toggle-ai-probability") {
    const form = actionNode.closest("#crm-opportunity-form");
    const probability = form?.querySelector('[name="probability"]');
    const mode = form?.querySelector('[name="probabilityMode"]');
    const manual = form?.querySelector('[name="manualProbability"]');
    if (!form || !probability || !mode || !manual) return;
    const enableAi = mode.value !== "ai";
    if (enableAi) {
      manual.value = probability.value || crmStageProbability(form.querySelector('[name="stage"]')?.value || "New");
      mode.value = "ai";
      probability.value = String(crmUpdateAiProbabilityPreview(form));
      probability.disabled = true;
    } else {
      mode.value = "manual";
      probability.disabled = false;
      probability.value = manual.value || crmStageProbability(form.querySelector('[name="stage"]')?.value || "New");
    }
    actionNode.setAttribute("aria-pressed", String(enableAi));
    actionNode.setAttribute("aria-label", enableAi ? "Use manual probability" : "Use AI-calculated probability");
    return;
  }
  if (action === "open-team") {
    const team = crmWorkspace.teams.find((item) => String(item.id) === String(actionNode.dataset.id));
    if (team) { crmWorkspace.teamEditor = { id: team.id, isNew: false, editMode: false }; crmWorkspace.selectedIds.clear(); render(); }
    return;
  }
  if (action === "team-back") { crmWorkspace.teamEditor = null; crmWorkspace.teamFilterDropdown = ""; render(); return; }
  if (action === "team-edit") { if (crmWorkspace.teamEditor) crmWorkspace.teamEditor.editMode = true; render(); return; }
  if (action === "team-discard") {
    const editor = crmWorkspace.teamEditor;
    if (editor?.isNew) crmWorkspace.teamEditor = null;
    else if (editor) editor.editMode = false;
    render(); return;
  }
  if (action === "team-archive") {
    const team = crmWorkspace.teams.find((item) => String(item.id) === String(crmWorkspace.teamEditor?.id));
    if (!team) return;
    team.active = team.active === false;
    if (!crmPersist("teams")) return;
    crmWorkspace.teamEditor = { id: team.id, isNew: false, editMode: false };
    render(); showToast(`${team.name} ${team.active ? "restored" : "archived"}.`); return;
  }
  if (action === "team-opportunities") {
    const team = crmWorkspace.teams.find((item) => String(item.id) === String(crmWorkspace.teamEditor?.id));
    if (!team) return;
    crmWorkspace.teamFilter = team.name; crmWorkspace.quickFilter = "All Opportunities"; crmWorkspace.search = ""; crmWorkspace.teamEditor = null; setPage("crm-pipeline"); return;
  }
  if (action === "team-filter-dropdown") { crmWorkspace.teamFilterDropdown = crmWorkspace.teamFilterDropdown ? "" : "filters"; render(); return; }
  if (action === "team-status-filter") { crmWorkspace.teamStatusFilter = actionNode.dataset.filter || "All"; crmWorkspace.teamFilterDropdown = "filters"; render(); return; }
  if (action === "team-opportunity-filter") { crmWorkspace.teamHasOpportunities = actionNode.dataset.filter || ""; crmWorkspace.teamFilterDropdown = "filters"; render(); return; }
  if (action === "team-group-by") { crmWorkspace.teamGroupBy = actionNode.dataset.group || ""; crmWorkspace.teamFilterDropdown = "filters"; render(); return; }
  if (action === "team-clear-selection") { crmWorkspace.selectedIds.clear(); render(); return; }
  if (action === "team-add-members") {
    const editor = crmWorkspace.teamEditor;
    const team = crmWorkspace.teams.find((item) => String(item.id) === String(editor?.id));
    if (editor?.isNew || !team) { showToast("Save the sales team before adding its members."); return; }
    crmOpenTeamMembers(team); return;
  }
  if (action === "team-chatter") { showToast(`${actionNode.dataset.type} is available when CRM is connected to a live Odoo server.`); return; }
  if (genericAction?.dataset.action === "new-record" && !actionNode) {
    if (state.page === "crm-pipeline") {
      crmWorkspace.view = "kanban";
      crmWorkspace.quickCreateStage = crmWorkspace.stages[0]?.name || "New";
      render();
      pageContent.querySelector('[data-crm-quick-create] input[name="contactName"]')?.focus();
    } else crmOpenOpportunity();
    event.preventDefault(); event.stopImmediatePropagation(); return;
  }
  if (genericAction?.dataset.action === "toolbar-more" && !actionNode) { crmWorkspace.dropdown = crmWorkspace.dropdown === "more" ? "" : "more"; render(); event.preventDefault(); event.stopImmediatePropagation(); return; }
  if (genericAction?.dataset.action === "upload" && !actionNode) { showToast("CRM import uses the Import records action. CSV mapping is available from the current list view."); event.preventDefault(); event.stopImmediatePropagation(); return; }
  if (!actionNode) return;
  if (action === "toggle-dropdown") { crmWorkspace.dropdown = crmWorkspace.dropdown === actionNode.dataset.dropdown ? "" : actionNode.dataset.dropdown; render(); return; }
  if (action === "forecast-filter-dropdown") { crmWorkspace.dropdown = crmWorkspace.dropdown === "forecast-filters" ? "" : "forecast-filters"; render(); return; }
  if (action === "forecast-toggle-filter" || action === "forecast-remove-filter") {
    const filter = actionNode.dataset.filter;
    if (action === "forecast-remove-filter") crmWorkspace.forecastFilters = crmWorkspace.forecastFilters.filter((item) => item !== filter);
    else crmWorkspace.forecastFilters = crmWorkspace.forecastFilters.includes(filter) ? crmWorkspace.forecastFilters.filter((item) => item !== filter) : [...crmWorkspace.forecastFilters, filter];
    crmWorkspace.dropdown = action === "forecast-toggle-filter" ? "forecast-filters" : ""; render(); return;
  }
  if (action === "forecast-group") { crmWorkspace.forecastGroupBy = actionNode.dataset.group || ""; crmWorkspace.dropdown = "forecast-filters"; render(); return; }
  if (action === "forecast-add") {
    const month = actionNode.dataset.month || ""; crmWorkspace.dropdown = ""; crmOpenOpportunity();
    const dateField = document.querySelector('#crm-opportunity-form [name="expectedClosing"]');
    if (dateField && month) dateField.value = `${month}-15`;
    return;
  }
  if (action === "forecast-next") { pageContent.querySelector(".crm-forecast-scroll")?.scrollBy({ left: 620, behavior: "smooth" }); return; }
  if (action === "team-filter") { crmWorkspace.teamFilter = actionNode.dataset.team || "All Teams"; crmWorkspace.dropdown = ""; render(); return; }
  if (action === "quick-filter") { crmWorkspace.quickFilter = actionNode.dataset.filter; crmWorkspace.dropdown = ""; render(); return; }
  if (action === "clear-quick-filter") { crmWorkspace.quickFilter = "All Opportunities"; render(); return; }
  if (action === "view") { crmWorkspace.view = actionNode.dataset.view; render(); return; }
  if (action === "quick-create") { crmWorkspace.quickCreateStage = actionNode.dataset.stage; render(); pageContent.querySelector('[data-crm-quick-create] input[name="contactName"]')?.focus(); return; }
  if (action === "cancel-quick-create") { crmWorkspace.quickCreateStage = ""; render(); return; }
  if (action === "discard-quick-create") { crmWorkspace.quickCreateStage = ""; render(); return; }
  if (action === "quick-priority") {
    const form = actionNode.closest("[data-crm-quick-create]");
    if (!form) return;
    const rating = Number(actionNode.dataset.rating || 0);
    form.querySelector('[name="priority"]').value = String(rating);
    form.querySelectorAll("[data-rating]").forEach((star) => {
      const selected = Number(star.dataset.rating) <= rating;
      star.textContent = selected ? "★" : "☆";
      star.setAttribute("aria-pressed", String(selected));
    });
    return;
  }
  if (action === "edit-quick-create") {
    const form = actionNode.closest("[data-crm-quick-create]");
    if (!form) return;
    const data = new FormData(form);
    const stage = form.dataset.crmQuickCreate;
    const contactName = String(data.get("contactName") || "").trim();
    const linkedContact = crmQuickContact(contactName);
    const draft = {
      id: crmId("crm-draft"), name: String(data.get("name") || "").trim(), contactName,
      company: linkedContact?.company || contactName, email: String(data.get("email") || "").trim(),
      phone: String(data.get("phone") || "").trim(), expectedRevenue: Math.max(0, Number(data.get("expectedRevenue") || 0)),
      stage, team: crmWorkspace.teamFilter === "All Teams" ? crmWorkspace.teams[0]?.name || "Sales" : crmWorkspace.teamFilter,
      salesperson: state.userProfile?.name || "", probability: crmStageProbability(stage), priority: Number(data.get("priority") || 0),
      tags: [], source: "Other", country: "Bangladesh", expectedClosing: inputLocalDate(new Date(Date.now() + 30 * 86400000)),
      currency: "$", isLead: false, notes: "", recurringRevenue: 0,
    };
    crmWorkspace.quickCreateStage = "";
    crmOpenOpportunity(draft, { stage });
    return;
  }
  if (action === "open-record") { const record = crmCurrentRecord(actionNode.dataset.id); if (record) crmOpenOpportunity(record); return; }
  if (action === "open-quotation") { setPage("sales-quotations"); openSalesOrderModal(actionNode.dataset.id, { returnPage: "crm-quotations" }); return; }
  if (action === "new-quotation") { setPage("sales-quotations"); openSalesOrderModal(null, { returnPage: "crm-quotations" }); return; }
  if (action === "new-customer") { openPartnerContactForm("dealer"); return; }
  if (action === "new-lead") { crmWorkspace.dropdown = ""; if (!crmWorkspace.settings.leads) { showToast("Enable Leads in CRM Settings before creating a lead."); return; } crmOpenOpportunity(null, { isLead: true }); return; }
  if (action === "open-mining") { crmWorkspace.dropdown = ""; if (!crmWorkspace.settings.leads || !crmWorkspace.settings.leadMining) { showToast("Enable Leads and Lead Mining in CRM Settings first."); return; } setPage("crm-lead-mining-requests"); crmRenderNewConfigForm("crm-lead-mining-requests"); return; }
  if (action === "view-services") { showToast("Lead mining and external enrichment use Odoo IAP services. Connect an Odoo server and service credits to activate them."); return; }
  if (action === "update-probabilities") { crmOpenProbabilityModal(); return; }
  if (action === "remove-probability-dimension") {
    const draft = crmWorkspace.probabilityDraft;
    if (!draft) return;
    const form = modalRoot.querySelector("#crm-probability-form");
    draft.cutoffDate = String(new FormData(form || document.createElement("form")).get("probabilityCutoffDate") || draft.cutoffDate);
    draft.fields = draft.fields.filter((key) => key !== actionNode.dataset.field);
    crmRenderProbabilityModal(); return;
  }
  if (action === "new-config") { crmRenderNewConfigForm(actionNode.dataset.kind); return; }
  if (action === "edit-config") { const schema = crmSchemas[actionNode.dataset.kind]; const record = schema && crmWorkspace[schema.key].find((item) => String(item.id) === String(actionNode.dataset.id)); if (record) crmOpenConfigForm(actionNode.dataset.kind, record); return; }
  if (action === "delete-config") {
    const schema = crmSchemas[actionNode.dataset.kind]; const record = schema && crmWorkspace[schema.key].find((item) => String(item.id) === String(actionNode.dataset.id));
    if (!record) return;
    if (actionNode.dataset.kind === "crm-stages" && crmWorkspace.opportunities.some((item) => item.stage === record.name)) { showToast("Move the opportunities in this stage before deleting it."); return; }
    if (actionNode.dataset.kind === "crm-sales-teams" && crmWorkspace.opportunities.some((item) => item.team === record.name)) { showToast("Reassign this team's opportunities before deleting it."); return; }
    crmWorkspace[schema.key] = crmWorkspace[schema.key].filter((item) => String(item.id) !== String(record.id));
    if (!crmPersist(schema.key)) return;
    render(); showToast(`${record.name} deleted.`); return;
  }
  if (action === "convert-opportunity") { const record = crmCurrentRecord(actionNode.dataset.id); if (record) { record.isLead = false; record.stage = "New"; record.probability = crmStageProbability("New"); if (!crmPersist("opportunities")) return; crmOpenOpportunity(record); showToast("Lead converted to an opportunity."); } return; }
  if (action === "create-quotation") { crmCreateQuotation(actionNode.dataset.id); return; }
  if (action === "convert-customer") { crmConvertToCustomer(actionNode.dataset.id); return; }
  if (action === "schedule-activity") { const record = crmCurrentRecord(actionNode.dataset.id); if (!record) { showToast("Save the opportunity before scheduling an activity."); return; } crmWorkspace.activeRecordId = record.id; crmOpenActivityForm(record); return; }
  if (action === "new-activity") { crmWorkspace.activeRecordId = ""; crmOpenActivityForm(); return; }
  if (action === "activity-done") { const record = crmWorkspace.activities.find((item) => String(item.id) === String(actionNode.dataset.id)); if (!record) return; record.status = record.status === "Done" ? "Planned" : "Done"; if (!crmPersist("activities")) return; render(); return; }
  if (action === "mark-lost") { if (!crmCurrentRecord(actionNode.dataset.id)) { showToast("Save the opportunity before marking it lost."); return; } crmOpenLostForm(actionNode.dataset.id); return; }
  if (action === "enrich-record") { crmEnrichRecord(actionNode.dataset.id); return; }
  if (action === "export") { crmExportRecords(); return; }
  if (action === "import") { crmImportCsv(); return; }
  if (action === "toggle-favorites") { crmWorkspace.quickFilter = "My Opportunities"; crmWorkspace.dropdown = ""; render(); return; }
  if (action === "filter-stage") { crmWorkspace.quickFilter = "All Opportunities"; crmWorkspace.view = "kanban"; render(); return; }
  if (action === "calendar-step") { crmWorkspace.calendarOffset = (crmWorkspace.calendarOffset || 0) + Number(actionNode.dataset.step || 0); render(); return; }
  if (action === "edit-partner-page") { if (!crmWorkspace.settings.partnerWebsite) { showToast("Enable Partners Website Page in CRM Settings first."); return; } showToast("The partner page opens after the Website app is connected to a live Odoo database."); return; }
  if (action === "discard-settings") { crmWorkspace.settings = crmReadSettings(); render(); return; }
  event.preventDefault(); event.stopImmediatePropagation();
}

function crmImportCsv() {
  const input = document.createElement("input"); input.type = "file"; input.accept = ".csv,text/csv";
  input.addEventListener("change", async () => {
    const file = input.files?.[0]; if (!file) return;
    const text = await file.text();
    const lines = text.split(/\r?\n/).filter(Boolean);
    if (lines.length < 2) { showToast("Choose a CSV file with a header row and at least one record."); return; }
    const headers = lines.shift().split(",").map((item) => item.trim().replace(/^"|"$/g, "").toLowerCase());
    const find = (...names) => names.map((name) => headers.indexOf(name)).find((index) => index >= 0);
    const nameIndex = find("opportunity", "lead", "name", "title");
    if (nameIndex < 0) { showToast("CSV needs an Opportunity, Lead, Name, or Title column."); return; }
    lines.forEach((line) => {
      const cells = line.match(/("(?:[^"]|"")*"|[^,]*)(?:,|$)/g)?.filter((item) => item !== "").map((item) => item.replace(/,$/, "").trim().replace(/^"|"$/g, "").replace(/""/g, '"')) || line.split(",");
      const field = (index) => index == null ? "" : String(cells[index] || "").trim();
      const name = field(nameIndex); if (!name) return;
      const value = Number(field(find("expected revenue", "revenue", "expectedrevenue")) || 0);
      const stage = field(find("stage")) || "New";
      crmWorkspace.opportunities.push({ id: crmId("crm-import"), name, contactName: field(find("contact", "customer")), company: field(find("company")), email: field(find("email")), phone: field(find("phone")), team: field(find("team", "sales team")) || crmWorkspace.teams[0]?.name || "Sales", salesperson: field(find("salesperson", "owner")), stage, expectedRevenue: value, probability: Number(field(find("probability")) || crmStageProbability(stage)), priority: 0, tags: field(find("tags")).split(/[;|]/).map((item) => item.trim()).filter(Boolean), expectedClosing: field(find("expected closing", "closing date")) || inputLocalDate(), source: field(find("source")) || "Other", currency: "$", isLead: /lead/i.test(field(find("type", "record type"))), createdAt: inputLocalDate() });
    });
    if (!crmPersist("opportunities")) return;
    render(); showToast("CRM records imported from CSV.");
  });
  input.click();
}

function crmHandleSubmit(event) {
  const form = event.target;
  if (form.id === "crm-probability-form") {
    event.preventDefault();
    const data = new FormData(form);
    const fields = [...new Set(data.getAll("probabilityFields").map(String).filter((key) => crmProbabilityDimensions.some(([field]) => field === key)))];
    const cutoffDate = String(data.get("probabilityCutoffDate") || "");
    if (!/^\d{4}-\d{2}-\d{2}$/.test(cutoffDate)) { form.querySelector('[name="probabilityCutoffDate"]')?.focus(); return; }
    crmUpdateProbabilities(fields, cutoffDate); return;
  }
  if (form.matches("[data-crm-quick-create]")) {
    event.preventDefault(); const data = new FormData(form); const name = String(data.get("name") || "").trim(); if (!name) return;
    const stage = form.dataset.crmQuickCreate; const contactName = String(data.get("contactName") || "").trim(); const linkedContact = crmQuickContact(contactName); const record = { id: crmId("crm"), name, contactName, company: linkedContact?.company || contactName, email: String(data.get("email") || "").trim(), phone: String(data.get("phone") || "").trim(), stage, team: crmWorkspace.teamFilter === "All Teams" ? crmWorkspace.teams[0]?.name || "Sales" : crmWorkspace.teamFilter, salesperson: state.userProfile?.name || "", expectedRevenue: Math.max(0, Number(data.get("expectedRevenue") || 0)), probability: crmStageProbability(stage), priority: Math.max(0, Math.min(3, Number(data.get("priority") || 0))), tags: [], source: "Other", country: "Bangladesh", expectedClosing: inputLocalDate(new Date(Date.now() + 30 * 86400000)), currency: "$", isLead: false, notes: "", recurringRevenue: 0 };
    if (crmWorkspace.settings.ruleBasedAssignment) record.salesperson = "";
    crmApplyAssignment(record); crmWorkspace.opportunities.unshift(record); crmWorkspace.quickCreateStage = ""; if (!crmPersist("opportunities")) return; render(); return;
  }
  if (form.id === "crm-settings-form") {
    event.preventDefault(); const data = new FormData(form);
    ["recurringRevenues", "leads", "multiTeams", "membership", "partnerWebsite", "ruleBasedAssignment", "leadEnrichment", "leadMining", "visitsToLeads"].forEach((key) => { crmWorkspace.settings[key] = data.has(key); });
    for (const key of ["memberLabel", "leadEnrichmentMode", "automaticPoFrequency", "defaultTeam", "defaultSalesperson"]) { const value = data.get(key); if (value !== null) crmWorkspace.settings[key] = String(value); }
    crmWorkspace.settings.minimumPoAmount = Math.max(0, Number(data.get("minimumPoAmount") || 0));
    if (!crmPersistSettings()) return; render(); showToast("CRM settings saved in this browser."); return;
  }
  if (form.id === "crm-opportunity-form") {
    event.preventDefault(); const data = new FormData(form); const id = form.dataset.id || crmId("crm"); const existing = crmCurrentRecord(id); const name = String(data.get("name") || "").trim(); if (!name) return;
    const stage = String(data.get("stage") || "New"); const contactName = String(data.get("contactName") || "").trim(); const company = String(data.get("company") || "").trim(); const lookupNames = [contactName, company].map((value) => value.toLowerCase()).filter(Boolean); const sharedContact = state.contacts.find((item) => [item.name, item.company, item.companyEmployer].some((value) => lookupNames.includes(String(value || "").trim().toLowerCase()))); const record = crmApplyAssignment({ ...(existing || {}), id, name, contactName, company, contactId: sharedContact?.id || null, email: String(data.get("email") || "").trim(), phone: String(data.get("phone") || "").trim(), expectedRevenue: Math.max(0, Number(data.get("expectedRevenue") || 0)), recurringRevenue: Math.max(0, Number(data.get("recurringRevenue") || 0)), probability: Math.max(0, Math.min(100, Number(data.get("probability") ?? crmStageProbability(stage)))), expectedClosing: String(data.get("expectedClosing") || ""), salesperson: String(data.get("salesperson") || state.userProfile?.name || "").trim(), team: String(data.get("team") || crmWorkspace.teams[0]?.name || "Sales"), stage, source: String(data.get("source") || "Other"), country: String(data.get("country") || "").trim(), state: String(data.get("state") || "").trim(), language: String(data.get("language") || "").trim(), priority: Math.max(0, Math.min(3, Number(data.get("priority") || 0))), tags: String(data.get("tags") || "").split(/[,;]/).map((item) => item.trim()).filter(Boolean), notes: String(data.get("notes") || "").trim(), currency: existing?.currency || form.dataset.currency || "৳", isLead: existing ? Boolean(existing.isLead) : form.dataset.type === "lead", active: true });
    const aiProbabilityMode = data.get("probabilityMode") === "ai";
    const typedProbability = Math.max(0, Math.min(100, Number(data.get("probability") ?? crmStageProbability(stage))));
    const manualProbability = Math.max(0, Math.min(100, Number(data.get("manualProbability") ?? typedProbability)));
    record.probabilityMode = aiProbabilityMode ? "ai" : "manual";
    record.manualProbability = aiProbabilityMode ? manualProbability : typedProbability;
    record.probability = aiProbabilityMode ? crmComputeProbability(record, crmWorkspace.settings.probabilityFields) : typedProbability;
    if (!existing && crmWorkspace.settings.ruleBasedAssignment && !String(data.get("salesperson") || "").trim()) { record.salesperson = ""; crmApplyAssignment(record); }
    if (!crmWorkspace.settings.recurringRevenues) record.recurringRevenue = Number(existing?.recurringRevenue || 0);
    record.memberLevel = String(data.get("memberLevel") || existing?.memberLevel || "").trim();
    if (!existing && form.closest(".crm-record-modal")?.querySelector(".crm-record-modal-header > div > span")?.textContent.includes("LEAD")) record.isLead = true;
    if (!crmStageByName(record.stage)) record.stage = crmWorkspace.stages[0]?.name || "New";
    const duplicate = crmWorkspace.opportunities.find((item) => String(item.id) !== String(id) && item.name.toLowerCase() === name.toLowerCase() && String(item.company || "").toLowerCase() === record.company.toLowerCase());
    if (duplicate) { showToast("An opportunity with this name and company already exists."); form.querySelector('[name="name"]')?.focus(); return; }
    const wasLead = existing?.isLead;
    const autoEnriched = record.isLead && crmWorkspace.settings.leadEnrichment && crmWorkspace.settings.leadEnrichmentMode === "automatic" && crmEnrichFromSharedContact(record);
    if (!crmPersistOpportunity(record, autoEnriched ? "CRM record saved and enriched from shared Contacts." : existing ? "CRM record updated." : "CRM record created.")) return;
    if (wasLead && !record.isLead) showToast("Lead converted to an opportunity."); return;
  }
  if (form.id === "crm-team-form") {
    event.preventDefault();
    const data = new FormData(form);
    const id = String(form.dataset.id || "");
    const isNew = form.dataset.isNew === "1";
    const existing = crmWorkspace.teams.find((item) => String(item.id) === id);
    const name = String(data.get("name") || "").trim();
    if (!name) { form.querySelector('[name="name"]')?.focus(); return; }
    const duplicate = crmWorkspace.teams.find((item) => String(item.id) !== id && String(item.name || "").trim().toLowerCase() === name.toLowerCase());
    if (duplicate) { showToast("A sales team with this name already exists."); form.querySelector('[name="name"]')?.focus(); return; }
    const team = {
      ...(existing || {}), id: existing?.id || id || crmId("crm-team"), name,
      alias: String(data.get("alias") || "").trim(), aliasDomain: String(data.get("aliasDomain") || "").trim(),
      acceptEmailsFrom: String(data.get("acceptEmailsFrom") || "Everyone"),
      leader: String(data.get("leader") || "").trim(), company: String(data.get("company") || "").trim(),
      members: crmTeamMembers(existing || { members: [], leader: String(data.get("leader") || "") }),
      active: existing?.active !== false, sequence: existing?.sequence || (Math.max(0, ...crmWorkspace.teams.map((item) => Number(item.sequence || 0))) + 10),
    };
    if (!team.members.length && team.leader) team.members = [team.leader];
    if (existing) Object.assign(existing, team); else crmWorkspace.teams.push(team);
    if (!crmPersist("teams")) return;
    crmWorkspace.teamEditor = { id: team.id, isNew: false, editMode: false };
    render(); showToast(`Sales team “${team.name}” ${isNew ? "created" : "updated"}.`); return;
  }
  if (form.id === "crm-team-members-form") {
    event.preventDefault();
    const team = crmWorkspace.teams.find((item) => String(item.id) === String(form.dataset.id));
    if (!team) return;
    team.members = [...new Set(new FormData(form).getAll("members").map((name) => String(name).trim()).filter(Boolean))];
    let movedMembers = 0;
    if (!crmWorkspace.settings.multiTeams) crmWorkspace.teams.filter((item) => String(item.id) !== String(team.id)).forEach((item) => {
      const members = crmTeamMembers(item);
      const filtered = members.filter((name) => !team.members.includes(name));
      if (filtered.length !== members.length) { item.members = filtered; if (item.leader && !filtered.includes(item.leader)) item.leader = filtered[0] || ""; movedMembers += members.length - filtered.length; }
    });
    if (!crmPersist("teams")) return;
    closeModal(); render(); showToast(movedMembers ? `${team.name} team members updated; ${movedMembers} salesperson${movedMembers === 1 ? "" : "s"} moved from another team in single-team mode.` : `${team.name} team members updated.`); return;
  }
  if (form.id === "crm-config-form") {
    event.preventDefault(); const kind = form.dataset.kind; const schema = crmSchemas[kind]; if (!schema) return; const data = new FormData(form); const existing = crmWorkspace[schema.key].find((item) => String(item.id) === String(form.dataset.id)); const record = { ...(existing || {}), id: existing?.id || crmId(schema.key), active: true };
    schema.fields.forEach(([key, , type]) => { if (type === "checkbox") record[key] = data.has(key); else if (type === "number") record[key] = Number(data.get(key) || 0); else record[key] = String(data.get(key) || "").trim(); });
    if (kind === "crm-stages") { const probability = Number(record.probability); if (!Number.isFinite(probability) || probability < 0 || probability > 100) { showToast("Stage probability must be between 0 and 100."); return; } if (record.won) record.probability = 100; }
    if (["crm-sales-teams", "crm-stages", "crm-tags"].includes(kind)) { const duplicate = crmWorkspace[schema.key].some((item) => String(item.id) !== String(record.id) && String(item.name).toLowerCase() === record.name.toLowerCase()); if (duplicate) { showToast(`${schema.title.slice(0, -1)} name already exists.`); return; } }
    if (kind === "crm-lead-mining-requests") record.status = "Waiting for Credits";
    if (kind === "crm-activity-plans") record.steps = Math.max(0, Number(record.steps || 0));
    if (kind === "crm-commission-plans") record.rate = Math.max(0, Math.min(100, Number(record.rate || 0)));
    if (existing) Object.assign(existing, record); else crmWorkspace[schema.key].push(record);
    if (kind === "crm-stages" && record.won) crmWorkspace.stages.forEach((stage) => { if (stage.id !== record.id) stage.won = false; });
    if (kind === "crm-stages") { record.sequence = Number(record.sequence || crmWorkspace.stages.length * 10); crmWorkspace.stages.sort((a, b) => Number(a.sequence || 0) - Number(b.sequence || 0)); }
    if (!crmPersist(schema.key)) return;
    closeModal(); render(); showToast(`${record.name || schema.title} ${existing ? "updated" : "created"}.`);
    if (kind === "crm-lead-mining-requests") showToast("Request saved. Connect Lead Mining credits in Odoo IAP to retrieve real company data.");
    return;
  }
  if (form.id === "crm-activity-form") {
    event.preventDefault(); const data = new FormData(form); const record = crmCurrentRecord(data.get("recordId")); if (!record) { showToast("Choose a valid lead or opportunity."); return; }
    crmWorkspace.activities.unshift({ id: crmId("activity"), type: String(data.get("type") || "To-Do"), summary: String(data.get("summary") || "").trim(), dueDate: String(data.get("dueDate") || ""), assignedTo: String(data.get("assignedTo") || "").trim(), recordId: record.id, recordName: record.name, note: String(data.get("note") || "").trim(), status: "Planned", requestedBy: state.userProfile?.name || "" });
    if (!crmPersist("activities")) return; crmWorkspace.activeRecordId = ""; closeModal(); render(); showToast("Activity scheduled on the CRM record."); return;
  }
  if (form.id === "crm-lost-form") {
    event.preventDefault(); const record = crmCurrentRecord(form.dataset.id); if (!record) return; const data = new FormData(form); record.lost = true; record.lostReason = String(data.get("reason") || "Other"); record.lostDate = inputLocalDate(); if (!crmPersist("opportunities")) return; closeModal(); render(); showToast(`Opportunity marked lost: ${record.lostReason}.`); return;
  }
}

function crmHandleInput(event) {
  const probabilityForm = event.target.closest("#crm-opportunity-form");
  if (probabilityForm && ["phone", "email", "source", "country", "state", "language", "tags", "stage"].includes(event.target.name)) crmUpdateAiProbabilityPreview(probabilityForm);
  if (state.module === "crm" && event.target.matches('[data-crm-quick-create] [name="contactName"]')) {
    const contact = crmQuickContact(event.target.value);
    if (contact) {
      const form = event.target.closest("[data-crm-quick-create]");
      form.querySelector('[name="email"]').value = contact.email || "";
      form.querySelector('[name="phone"]').value = contact.phone || "";
    }
  }
  if (event.target.id === "crm-search" || event.target.matches("[data-crm-config-search]")) {
    crmWorkspace.search = event.target.value;
    const query = crmWorkspace.search.trim().toLowerCase();
    pageContent.querySelectorAll("[data-crm-searchable]").forEach((node) => { node.hidden = Boolean(query) && !node.dataset.crmSearchable.includes(query); });
    if (state.page === "crm-forecast") pageContent.querySelectorAll(".crm-forecast-column").forEach((column) => {
      const cards = [...column.querySelectorAll(".crm-forecast-card")].filter((card) => !card.hidden);
      const total = cards.reduce((sum, card) => sum + (Number((card.querySelector(".crm-card-value")?.textContent || "").replace(/[^0-9.-]/g, "")) || 0), 0);
      const won = cards.filter((card) => card.dataset.forecastWon === "true").reduce((sum, card) => sum + (Number((card.querySelector(".crm-card-value")?.textContent || "").replace(/[^0-9.-]/g, "")) || 0), 0);
      const probable = cards.filter((card) => card.dataset.forecastWon !== "true" && Number(card.dataset.forecastProbability || 0) >= 50).reduce((sum, card) => sum + (Number((card.querySelector(".crm-card-value")?.textContent || "").replace(/[^0-9.-]/g, "")) || 0), 0);
      const basis = total || cards.length || 1;
      const totals = column.querySelector(".crm-forecast-totals");
      if (!totals) return;
      totals.querySelector("strong").textContent = crmFormatMoney(total, "$");
      const badge = totals.querySelector(":scope > span");
      if (badge) { badge.textContent = String(cards.length); badge.classList.toggle("is-empty", !cards.length); }
      const bar = totals.querySelector(".crm-forecast-progress");
      if (bar) {
        const green = total ? won / basis * 100 : cards.filter((card) => card.dataset.forecastWon === "true").length / basis * 100;
        const yellow = total ? probable / basis * 100 : cards.filter((card) => card.dataset.forecastWon !== "true" && Number(card.dataset.forecastProbability || 0) >= 50).length / basis * 100;
        bar.querySelector("i").style.width = String(green) + "%"; bar.querySelector("b").style.width = String(yellow) + "%"; bar.querySelector("em").style.width = String(cards.length ? Math.max(0, 100 - green - yellow) : 0) + "%";
      }
    });
    const count = pageContent.querySelector(".crm-config-count");
    if (count && state.page === "crm-sales-teams") { const visible = pageContent.querySelectorAll("[data-crm-team-row]:not([hidden])").length; count.textContent = visible ? `1-${visible} / ${crmWorkspace.teams.length}` : `0 / ${crmWorkspace.teams.length}`; }
    else if (count) count.textContent = `${pageContent.querySelectorAll(".crm-config-table tbody tr:not([hidden])").length} / ${(crmWorkspace[state.page] || []).length}`;
  }
}

function crmHandleChange(event) {
  const probabilityForm = event.target.closest("#crm-opportunity-form");
  if (probabilityForm && ["phone", "email", "source", "country", "state", "language", "tags", "stage"].includes(event.target.name)) crmUpdateAiProbabilityPreview(probabilityForm);
  if (event.target.matches("[data-crm-probability-field]")) {
    const draft = crmWorkspace.probabilityDraft;
    const field = event.target.value;
    if (!draft || !field) return;
    const date = modalRoot.querySelector('[name="probabilityCutoffDate"]')?.value;
    if (date) draft.cutoffDate = date;
    if (!draft.fields.includes(field)) draft.fields.push(field);
    crmRenderProbabilityModal(); return;
  }
  if (event.target.matches('#crm-settings-form [data-crm-setting-toggle]')) {
    const row = event.target.closest(".crm-setting-row");
    const dependent = row?.querySelector("[data-setting-dependent]");
    if (dependent) {
      const enabled = event.target.checked;
      dependent.toggleAttribute("inert", !enabled);
      dependent.setAttribute("aria-disabled", String(!enabled));
      dependent.querySelectorAll("input, select, button").forEach((control) => { control.disabled = !enabled; });
    }
    return;
  }
  if (event.target.matches("[data-crm-graph-metric]")) { crmWorkspace.chartMetric = event.target.value; render(); return; }
  if (event.target.matches("[data-crm-activity-filter]")) { crmWorkspace.activityFilter = event.target.value; render(); return; }
  if (event.target.matches("[data-crm-select]")) { const id = String(event.target.dataset.crmSelect); if (event.target.checked) crmWorkspace.selectedIds.add(id); else crmWorkspace.selectedIds.delete(id); if (state.page === "crm-sales-teams") { const banner = pageContent.querySelector(".crm-team-selection"); if (banner) banner.remove(); if (crmWorkspace.selectedIds.size) pageContent.querySelector(".crm-team-list-page")?.insertAdjacentHTML("afterbegin", `<div class="crm-team-selection"><span>${crmWorkspace.selectedIds.size} selected</span><button type="button" data-crm-action="team-clear-selection">×</button></div>`); } return; }
  if (event.target.matches("[data-crm-select-all]")) { crmWorkspace.selectedIds.clear(); if (event.target.checked) pageContent.querySelectorAll("[data-crm-select]").forEach((node) => crmWorkspace.selectedIds.add(String(node.dataset.crmSelect))); render(); }
}

document.addEventListener("click", crmHandleClick);
document.addEventListener("submit", crmHandleSubmit);
document.addEventListener("input", crmHandleInput);
document.addEventListener("change", crmHandleChange);
document.addEventListener("dragstart", (event) => {
  const teamHandle = event.target.closest("[data-crm-team-drag]");
  if (teamHandle && state.module === "crm" && state.page === "crm-sales-teams" && !crmWorkspace.teamEditor) { crmWorkspace.teamDraggingId = teamHandle.dataset.crmTeamDrag; event.dataTransfer?.setData("text/plain", crmWorkspace.teamDraggingId); if (event.dataTransfer) event.dataTransfer.effectAllowed = "move"; teamHandle.closest("tr")?.classList.add("is-dragging"); return; }
  const card = event.target.closest("[data-crm-card]"); if (!card || state.module !== "crm") return; crmWorkspace.draggingId = card.dataset.crmCard; card.classList.add("is-dragging"); event.dataTransfer?.setData("text/plain", crmWorkspace.draggingId); event.dataTransfer && (event.dataTransfer.effectAllowed = "move");
});
document.addEventListener("dragover", (event) => {
  const teamRow = event.target.closest("[data-crm-team-row]");
  if (teamRow && state.module === "crm" && state.page === "crm-sales-teams" && crmWorkspace.teamDraggingId) { event.preventDefault(); teamRow.classList.add("is-drag-target"); return; }
  const column = event.target.closest("[data-crm-stage-drop]");
  if (column && state.module === "crm" && crmWorkspace.draggingId) {
    event.preventDefault();
    pageContent.querySelectorAll(".crm-stage-column.is-drop-target").forEach((item) => { if (item !== column) item.classList.remove("is-drop-target"); });
    column.classList.add("is-drop-target");
  }
});
document.addEventListener("dragleave", (event) => {
  event.target.closest("[data-crm-team-row]")?.classList.remove("is-drop-target");
  const column = event.target.closest("[data-crm-stage-drop]");
  if (column && !column.classList.contains("is-processing") && !column.contains(event.relatedTarget)) column.classList.remove("is-drop-target");
});
document.addEventListener("dragend", (event) => {
  const card = event.target.closest("[data-crm-card]");
  if (card) card.classList.remove("is-dragging");
  crmWorkspace.draggingId = "";
  pageContent.querySelectorAll(".crm-stage-column.is-drop-target:not(.is-processing)").forEach((item) => item.classList.remove("is-drop-target"));
});
document.addEventListener("drop", (event) => {
  const teamRow = event.target.closest("[data-crm-team-row]");
  if (teamRow && state.module === "crm" && state.page === "crm-sales-teams") {
    event.preventDefault();
    const fromId = event.dataTransfer?.getData("text/plain") || crmWorkspace.teamDraggingId;
    const toId = teamRow.dataset.crmTeamRow;
    if (fromId && toId && fromId !== toId) {
      const ordered = [...crmWorkspace.teams].sort((a, b) => Number(a.sequence || 0) - Number(b.sequence || 0));
      const from = ordered.findIndex((team) => String(team.id) === String(fromId)); const to = ordered.findIndex((team) => String(team.id) === String(toId));
      if (from >= 0 && to >= 0) { const [moved] = ordered.splice(from, 1); ordered.splice(to, 0, moved); ordered.forEach((team, index) => { team.sequence = (index + 1) * 10; }); crmWorkspace.teams = ordered; if (!crmPersist("teams")) return; render(); showToast(`${moved.name} team order updated.`); }
    }
    crmWorkspace.teamDraggingId = ""; return;
  }
  const column = event.target.closest("[data-crm-stage-drop]"); if (!column || state.module !== "crm") return; event.preventDefault();
  const id = event.dataTransfer?.getData("text/plain") || crmWorkspace.draggingId;
  const record = crmCurrentRecord(id);
  const destination = column.dataset.crmStageDrop;
  if (!record || !destination || record.stage === destination) { crmWorkspace.draggingId = ""; return; }
  crmWorkspace.stageMove = { id, stage: destination };
  crmWorkspace.draggingId = "";
  render();
  window.setTimeout(() => {
    const current = crmCurrentRecord(id);
    crmWorkspace.stageMove = null;
    if (!current) { render(); return; }
    const moved = { ...current, stage: destination };
    moved.probability = moved.probabilityMode === "ai" ? crmComputeProbability(moved, crmWorkspace.settings.probabilityFields) : crmStageProbability(destination);
    moved.manualProbability = moved.probabilityMode === "ai" ? Number(current.manualProbability ?? current.probability ?? 0) : moved.probability;
    if (!crmPersistOpportunity(moved, `Opportunity moved to ${destination}.`)) { render(); return; }
    const arrivingCard = [...pageContent.querySelectorAll("[data-crm-card]")].find((item) => item.dataset.crmCard === String(id));
    if (arrivingCard) {
      arrivingCard.classList.add("crm-card-arrival");
      window.setTimeout(() => arrivingCard.classList.remove("crm-card-arrival"), 520);
    }
  }, 280);
});

function crmNewOpportunityForContact(contact) {
  if (!contact) return;
  crmOpenOpportunity({
    contactName: String(contact.name || "").trim(),
    company: String(contact.company || contact.companyEmployer || "").trim(),
    email: String(contact.email || "").trim(),
    phone: String(contact.phone || "").trim(),
  });
}

function renderCrmPage() { return crmWorkspace.page?.() || crmRenderPage(); }

window.crmModule = { renderPage: crmRenderPage, renderSettingsContent: crmRenderSettingsContent, salesOrderSaved: crmSalesOrderSaved, openOpportunity: (id) => { const record = crmCurrentRecord(id); if (record) crmOpenOpportunity(record); else showToast("The linked CRM opportunity is no longer available."); }, newOpportunityForContact: crmNewOpportunityForContact, getWorkspace: () => crmWorkspace };
