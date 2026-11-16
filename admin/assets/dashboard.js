/*
 * Admin dashboard: draws the charts (Chart.js) and re-applies the filters when one changes.
 * The data comes from <script id="chart-data"> written by dashboard.php.
 */
(function () {
  'use strict';

  // Filters: apply as soon as a filter changes (the Apply button still works without JavaScript) ----
  var form = document.getElementById('filters');
  if (form) {
    var multi   = document.getElementById('platform-filter');   // the "Social media" checkbox dropdown
    var summary = document.getElementById('platform-summary');
    var boxes   = multi ? multi.querySelectorAll('input[type=checkbox]') : [];
    var multiChanged = false;

    // Text shown on the closed dropdown: "All social media", "Facebook, X" or "3 selected"
    var updateSummary = function () {
      var names = [];
      boxes.forEach(function (b) { if (b.checked) { names.push(b.getAttribute('data-name')); } });
      summary.textContent = (names.length === 0 || names.length === boxes.length) ? 'All social media'
        : names.length <= 2 ? names.join(', ')
        : names.length + ' selected';
    };

    // Close the dropdown; if boxes were ticked, apply the filter now
    var closeMulti = function () {
      multi.open = false;
      if (multiChanged) { multiChanged = false; form.submit(); }
    };

    form.addEventListener('change', function (e) {
      if (e.target.id === 'page-search') { return; }   // the page box submits by itself when a page is picked
      if (multi && multi.contains(e.target)) {      // ticking several boxes: wait until the dropdown closes
        multiChanged = true;
        updateSummary();
        return;
      }
      form.submit();
    });

    if (multi) {
      multi.addEventListener('click', function (e) {     // "Select all" / "Clear"
        var action = e.target.getAttribute ? e.target.getAttribute('data-multi') : null;
        if (action) {
          boxes.forEach(function (b) { b.checked = (action === 'all'); });
          multiChanged = true;
          updateSummary();
        }
      });
      document.addEventListener('click', function (e) {  // click outside = close
        if (multi.open && !multi.contains(e.target)) { closeMulti(); }
      });
      document.addEventListener('keydown', function (e) { // Esc = close
        if (e.key === 'Escape' && multi.open) { closeMulti(); }
      });
    }
  }

  // Page filter: a search box. It asks the server for a few matches, so it stays fast with thousands of pages.
  var pInput = document.getElementById('page-search');
  if (form && pInput && window.fetch) {
    var pValue = document.getElementById('page-value');
    var pList  = document.getElementById('page-list');
    var pClear = document.getElementById('page-clear');
    var pLabel = pInput.value;      // text of the page that is selected now
    var timer = null, seq = 0, active = -1;

    var setOpen = function (open) {
      pList.hidden = !open;
      pInput.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (!open) { active = -1; }
    };

    // pick a page ('' = all pages, 'home' = the home page, or a post id) and reload with the new filter
    var choose = function (value, label) {
      var changed = value !== pValue.value;
      pValue.value = value;
      pInput.value = label;
      pLabel = label;
      setOpen(false);
      if (changed) { form.submit(); }
    };

    var highlight = function (i) {
      var items = pList.children;
      if (!items.length) { return; }
      active = (i + items.length) % items.length;
      for (var k = 0; k < items.length; k++) {
        items[k].classList.toggle('is-active', k === active);
        items[k].setAttribute('aria-selected', k === active ? 'true' : 'false');
      }
      items[active].scrollIntoView({ block: 'nearest' });
    };

    var addItem = function (value, title, sub) {
      var li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.dataset.value = value;
      li.dataset.label = title;
      var t = document.createElement('span');   // textContent, so a title is never read as HTML
      t.className = 'combo-title';
      t.textContent = title;
      li.appendChild(t);
      if (sub) {
        var u = document.createElement('span');
        u.className = 'combo-sub';
        u.textContent = sub;
        li.appendChild(u);
      }
      pList.appendChild(li);
    };

    var search = function (q) {
      var mine = ++seq;     // ignore answers that arrive late
      fetch('pages.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : []; })
        .then(function (rows) {
          if (mine !== seq) { return; }
          pList.innerHTML = '';
          addItem('', 'All pages', '');
          rows.forEach(function (row) {
            addItem(row.id, row.title, row.sub);
          });
          if (!rows.length && q !== '') {
            var none = document.createElement('li');
            none.className = 'combo-empty';
            none.textContent = 'No pages match "' + q + '"';
            pList.appendChild(none);
          }
          setOpen(true);
        })
        .catch(function () { /* if the search fails, the current filter still works */ });
    };

    pInput.addEventListener('focus', function () { pInput.select(); search(''); });
    pInput.addEventListener('input', function () {
      clearTimeout(timer);
      var q = pInput.value.trim();
      timer = setTimeout(function () { search(q); }, 250);   // wait a moment while the admin is still typing
    });
    pInput.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (pList.hidden) { search(pInput.value.trim()); } else { highlight(active + 1); }
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlight(active - 1);
      } else if (e.key === 'Enter') {
        e.preventDefault();   // never submit the form by accident
        if (!pList.hidden && active >= 0) {
          var li = pList.children[active];
          choose(li.dataset.value, li.dataset.value === '' ? '' : li.dataset.label);
        }
      } else if (e.key === 'Escape') {
        setOpen(false);
        pInput.value = pLabel;
      }
    });
    pList.addEventListener('mousedown', function (e) {   // mousedown, because it happens before the box loses focus
      var li = e.target.closest ? e.target.closest('li[role=option]') : null;
      if (!li) { return; }
      e.preventDefault();
      choose(li.dataset.value, li.dataset.value === '' ? '' : li.dataset.label);
    });
    pInput.addEventListener('blur', function () {
      setOpen(false);
      pInput.value = pLabel;    // text that was typed but not picked is dropped
    });
    pClear.addEventListener('click', function () { choose('', ''); });
  }

  // ---- Charts ----
  var dataEl = document.getElementById('chart-data');
  if (!dataEl || !window.Chart) { return; }

  var data = JSON.parse(dataEl.textContent);
  var root = document.querySelector('.viz-root');
  var css  = getComputedStyle(root);
  var tok  = function (name) { return css.getPropertyValue(name).trim(); };

  var C = {
    surface: tok('--surface-1'),
    text1:   tok('--text-primary'),
    text2:   tok('--text-secondary'),
    muted:   tok('--text-muted'),
    grid:    tok('--grid')
  };

  Chart.defaults.font.family = 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif';
  Chart.defaults.font.size   = 12;
  Chart.defaults.color       = C.text2;

  function compact(v) {
    return v >= 1000 ? (v / 1000).toFixed(v % 1000 ? 1 : 0).replace('.0', '') + 'K' : String(v);
  }

  function truncate(text, max) {
    return text.length > max ? text.slice(0, max - 1) + '…' : text;
  }

  // Hairline, solid, recessive axes
  function valueAxis(stacked) {
    return {
      stacked: !!stacked,
      beginAtZero: true,
      grid: { color: C.grid, lineWidth: 1 },
      border: { display: false },
      ticks: { precision: 0, color: C.muted, callback: compact }
    };
  }

  function categoryAxis(stacked, horizontal) {
    return {
      stacked: !!stacked,
      grid: { display: false },
      border: { color: C.grid },
      ticks: horizontal
        ? { color: C.text2, callback: function (v) { return truncate(this.getLabelForValue(v), 26); } }
        : { color: C.muted, maxRotation: 0, autoSkip: true }
    };
  }

  // Shared tooltip look: value first, name second, a short line as the colour key
  function tooltip(extra, keep) {
    var base = {
      backgroundColor: C.surface,
      borderColor: C.grid,
      borderWidth: 1,
      titleColor: C.text1,
      bodyColor: C.text2,
      footerColor: C.text1,
      padding: 10,
      boxPadding: 4,
      filter: keep,   // hides platforms that have 0 clicks in the hovered bar
      usePointStyle: true,
      callbacks: {
        labelColor: function (ctx) {
          var c = ctx.dataset.backgroundColor;
          c = Array.isArray(c) ? c[ctx.dataIndex] : c;
          return { borderColor: c, backgroundColor: c };
        },
        labelPointStyle: function () { return { pointStyle: 'line', rotation: 0 }; },
        label: function (ctx) {
          var name = ctx.chart.config.type === 'bar' && ctx.dataset.label ? ctx.dataset.label : ctx.label;
          return ' ' + ctx.parsed[ctx.chart.options.indexAxis === 'y' ? 'x' : 'y'] + '   ' + name;
        }
      }
    };
    if (extra) {
      for (var k in extra) { base.callbacks[k] = extra[k]; }
    }
    return base;
  }

  var legend = {
    position: 'top',
    align: 'start',
    labels: { usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 10, boxHeight: 10, color: C.text2, padding: 14 }
  };

  // Writes the total at the end of each horizontal bar (a number only where it helps)
  var endValues = {
    id: 'endValues',
    afterDatasetsDraw: function (chart) {
      if (chart.options.indexAxis !== 'y') { return; }
      var ctx = chart.ctx;
      var count = chart.data.labels.length;
      ctx.save();
      ctx.fillStyle = C.text1;
      ctx.font = '600 12px ' + Chart.defaults.font.family;
      ctx.textBaseline = 'middle';
      for (var i = 0; i < count; i++) {
        var total = 0, last = null;
        chart.data.datasets.forEach(function (ds, di) {
          if (chart.isDatasetVisible(di) && ds.data[i] > 0) {
            total += ds.data[i];
            last = chart.getDatasetMeta(di).data[i];
          }
        });
        if (last && total > 0) { ctx.fillText(String(total), last.x + 8, last.y); }
      }
      ctx.restore();
    }
  };

  function stackedDatasets(series) {
    return series.map(function (s) {
      return {
        label: s.name,
        data: s.data,
        backgroundColor: s.color,
        borderColor: C.surface,   // the 2px "gap" between touching segments
        borderWidth: 2,
        borderRadius: 3,
        borderSkipped: false,
        maxBarThickness: 24,
        stack: 'clicks'
      };
    });
  }

  // 1) Clicks over time - stacked columns, one colour per platform
  var timeCanvas = document.getElementById('chart-time');
  if (timeCanvas) {
    new Chart(timeCanvas, {
      type: 'bar',
      data: { labels: data.timeline.labels, datasets: stackedDatasets(data.timeline.series) },
      options: {
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: { x: categoryAxis(true, false), y: valueAxis(true) },
        plugins: {
          legend: legend,
          tooltip: tooltip({
            title: function (items) { return data.timeline.titles[items[0].dataIndex]; },
            footer: function (items) {
              if (items.length < 2) { return ''; }
              var sum = 0;
              items.forEach(function (it) { sum += it.parsed.y; });
              return 'Total   ' + sum;
            }
          }, function (item) { return item.parsed.y > 0; })
        }
      }
    });
  }

  // 2) Clicks by social media - horizontal bars, platform colours
  var platCanvas = document.getElementById('chart-platforms');
  if (platCanvas) {
    new Chart(platCanvas, {
      type: 'bar',
      data: {
        labels: data.platforms.labels,
        datasets: [{
          label: 'Clicks',
          data: data.platforms.data,
          backgroundColor: data.platforms.colors,
          borderColor: C.surface,
          borderWidth: 2,
          borderRadius: { topLeft: 0, bottomLeft: 0, topRight: 4, bottomRight: 4 },
          borderSkipped: false,
          maxBarThickness: 24
        }]
      },
      options: {
        indexAxis: 'y',
        maintainAspectRatio: false,
        layout: { padding: { right: 40 } },
        scales: { x: valueAxis(false), y: categoryAxis(false, true) },
        plugins: {
          legend: { display: false },
          tooltip: tooltip({
            title: function (items) { return items[0].label; },
            label: function (ctx) { return ' ' + ctx.parsed.x + '   clicks'; }
          })
        }
      },
      plugins: [endValues]
    });
  }

  // 3) Most shared pages - horizontal stacked bars, split by platform
  var pagesCanvas = document.getElementById('chart-pages');
  if (pagesCanvas) {
    new Chart(pagesCanvas, {
      type: 'bar',
      data: { labels: data.pages.labels, datasets: stackedDatasets(data.pages.series) },
      options: {
        indexAxis: 'y',
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false, axis: 'y' },
        layout: { padding: { right: 40 } },
        scales: { x: valueAxis(true), y: categoryAxis(true, true) },
        plugins: {
          legend: legend,
          tooltip: tooltip({
            title: function (items) { return items[0].label; },
            afterTitle: function (items) { return data.pages.urls[items[0].dataIndex]; },
            footer: function (items) {
              if (items.length < 2) { return ''; }
              var sum = 0;
              items.forEach(function (it) { sum += it.parsed.x; });
              return 'Total   ' + sum;
            }
          }, function (item) { return item.parsed.x > 0; })
        }
      },
      plugins: [endValues]
    });
  }
})();
