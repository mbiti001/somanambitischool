/* Derived from the existing account state; the dashboard stores no separate progress. */
const LearningDashboard = (() => {
  function orderedLessons(catalogue) {
    const names = [...new Set(catalogue.map(lesson => lesson.module))];
    return names.flatMap(name => catalogue.filter(lesson => lesson.module === name));
  }
  function summary(catalogue, state, stocks) {
    const completed = new Set(state.completed);
    const names = [...new Set(catalogue.map(lesson => lesson.module))];
    const stages = names.map(name => {
      const items = catalogue.filter(lesson => lesson.module === name);
      return {name, total: items.length, done: items.filter(lesson => completed.has(lesson.id)).length,
        next: items.find(lesson => !completed.has(lesson.id)) || items[0]};
    });
    const next = stages.find(stage => stage.done < stage.total)?.next || null;
    const entries = state.journal || [];
    const journalled = new Set(entries.map(entry => entry.trade.id));
    const unwritten = state.history.filter(trade => !journalled.has(trade.id));
    const unreviewed = entries.filter(entry => !entry.reviewed);
    const entry = unreviewed.at(-1) || null;
    const trade = unwritten.at(-1) || null;
    const reflection = entry ? {kind: 'review', entryId: entry.id, trade: entry.trade} :
      trade ? {kind: 'write', tradeId: trade.id, trade} :
      entries.length ? {kind: 'revisit', entryId: entries.at(-1).id, trade: entries.at(-1).trade} :
      {kind: 'start'};
    const holdings = stocks.filter(stock => (state.holdings[stock.s] || 0) > 0);
    const sharesValue = holdings.reduce((total, stock) => total + state.holdings[stock.s] * stock.p, 0);
    return {stages, next, total: catalogue.length, done: catalogue.filter(lesson => completed.has(lesson.id)).length,
      entries: entries.length, reviewed: entries.length - unreviewed.length,
      unreviewed: unreviewed.length, unwritten: unwritten.length, reflection,
      cash: state.cash, sharesValue, portfolioValue: state.cash + sharesValue,
      holdingCount: holdings.length, tradeCount: state.history.length};
  }
  return Object.freeze({summary, orderedLessons});
})();

function dashboardNode(tag, text, className) {
  const element = document.createElement(tag);
  if (text !== undefined) element.textContent = text;
  if (className) element.className = className;
  return element;
}

function initDashboardNavigation() {
  const toggle = $('nav-toggle'), nav = $('primary-nav');
  const close = () => {
    toggle.setAttribute('aria-expanded', 'false');
    nav.dataset.open = 'false';
  };
  toggle.onclick = () => {
    const expanded = toggle.getAttribute('aria-expanded') !== 'true';
    toggle.setAttribute('aria-expanded', String(expanded));
    nav.dataset.open = String(expanded);
  };
  nav.addEventListener('click', event => {
    if (event.target.closest('a')) close();
  });
  nav.addEventListener('keydown', event => {
    if (event.key === 'Escape') { close(); toggle.focus(); }
  });
}

function renderDashboard(){if(typeof renderCampusDashboard==='function')renderCampusDashboard();}
