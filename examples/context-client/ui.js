const element = id => document.getElementById(id);
let csrf = '';
let requestId = null;
let busy = false;
const display = (id, value) => { element(id).textContent = typeof value === 'string' ? value : JSON.stringify(value, null, 2); };
function clearLocal() {
  requestId = null;
  for (const id of ['bundle', 'metrics', 'answer-output', 'baseline-output']) display(id, '');
  for (const id of ['question', 'from', 'to']) element(id).value = '';
  element('consent').checked = false;
}
let expiry;
function renewDisplay() {
  clearTimeout(expiry);
  expiry = setTimeout(() => {
    clearLocal();
    post('/api/clear', {}).catch(() => {});
    display('status', 'The inactive display has been cleared. Reload to establish a fresh session if needed.');
  }, 10 * 60 * 1000);
}
document.addEventListener('pointerdown', renewDisplay);
document.addEventListener('keydown', renewDisplay);
window.addEventListener('pagehide', () => {
  clearLocal();
  if (csrf) fetch('/api/clear', { method: 'POST', keepalive: true,
    headers: { 'Content-Type': 'application/json', 'X-Reference-CSRF': csrf }, body: '{}' }).catch(() => {});
});
renewDisplay();

async function post(path, body) {
  const response = await fetch(path, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Reference-CSRF': csrf }, body: JSON.stringify(body) });
  const result = await response.json();
  if (!response.ok) throw new Error(result.error || 'Request failed.');
  return result;
}

async function run(action) {
  if (busy) return;
  busy = true;
  display('status', 'Request in progress.');
  try { await action(); display('status', 'Request completed. Inspect evidence and limitations before use.'); }
  catch (error) { display('status', error.message); }
  finally { busy = false; element('consent').checked = false; }
}

element('resolve').addEventListener('submit', event => {
  event.preventDefault();
  run(async () => {
    requestId = null;
    for (const id of ['bundle', 'metrics', 'answer-output', 'baseline-output']) display(id, '');
    for (const id of ['relevant', 'irrelevant', 'benefit']) element(id).value = 'not_reviewed';
    const temporal = element('temporal').checked;
    const result = await post('/api/resolve', { question: element('question').value, query_id: element('query-id').value,
      sources: [...document.querySelectorAll('input[name="source"]:checked')].map(input => input.value), temporal,
      from: temporal ? '' : element('from').value, to: temporal ? '' : element('to').value });
    requestId = result.bundle.request_id;
    display('bundle', result.bundle);
    display('metrics', result.metrics);
  });
});

for (const [button, baseline, output] of [['answer', false, 'answer-output'], ['baseline', true, 'baseline-output']]) {
  element(button).addEventListener('click', () => run(async () => {
    if (!requestId || !element('consent').checked) throw new Error('Inspect resolved context and confirm this single send first.');
    const result = await post('/api/answer', { request_id: requestId, consent: true, baseline });
    display(output, result.answer + '\n\n' + JSON.stringify({ attribution: result.attribution, measurements: result.metrics, observation: result.observation }, null, 2));
  }));
}

element('evaluation').addEventListener('submit', event => {
  event.preventDefault();
  run(() => post('/api/evaluate', { request_id: requestId, relevant_evidence: element('relevant').value,
    irrelevant_dominated: element('irrelevant').value, material_benefit: element('benefit').value }));
});
element('export').addEventListener('click', () => run(async () => {
  const result = await post('/api/measurements', {});
  const url = URL.createObjectURL(new Blob([JSON.stringify(result, null, 2)], { type: 'application/json' }));
  const link = document.createElement('a');
  link.href = url;
  link.download = 'openmemory-validation-measurements.json';
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}));
element('clear').addEventListener('click', () => run(async () => {
  await post('/api/clear', {});
  clearLocal();
}));

try {
  const response = await fetch('/api/session');
  if (!response.ok) throw new Error('Local session unavailable.');
  const config = await response.json();
  csrf = config.csrf;
  display('destination', `Model destination: ${config.destination}; model: ${config.model || 'not configured'}; reasoning: ${config.reasoning}. No web, tools, or conversation state.`);
  element('answer').disabled = element('baseline').disabled = !config.model_configured;
} catch { display('status', 'Could not establish the local client session.'); }
