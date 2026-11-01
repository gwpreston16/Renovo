/**
 * The scenario planner sends only the rows that change something (K175).
 *
 * Every row carries a Keep / Cancel / Change choice plus three amount fields,
 * so the whole form is several thousand bytes and four request variables per
 * subscription. Sent as a GET, past about 80 rows that is longer than the web
 * server's request-line limit. A row left on Keep contributes nothing to the
 * scenario (`Scenario::fromQuery()` treats a missing row as Keep), so it is
 * left out of the request, and the request is as long as the changes made
 * rather than the number of subscriptions.
 *
 * The form is still complete in the page: what was typed into a row stays in
 * its fields, so choosing Change again shows it. Only the request is trimmed.
 *
 * Without script the form posts whole (it is a POST, so no address length
 * applies) and the server answers with the short address
 * (`ScenarioService::queryFromForm()`).
 */

const FORM = 'scenario-form';
const SCENARIO_PATH = '/forecast/scenario';
const CSRF_FIELD = '_csrf';
const ROW_FIELD = /^(?:choice|change)\[(\d+)\]/;
const CHOICE_FIELD = /^choice\[(\d+)\]$/;

/** One parameter's value as htmx hands it over: a string, or a list when repeated. */
function valueOf(parameter) {
    return String(Array.isArray(parameter) ? parameter[parameter.length - 1] : parameter);
}

/**
 * Remove every parameter of a row that is set to Keep, from an htmx request's
 * parameters (an object that supports `delete`).
 */
export function dropKeptRows(parameters) {
    const names = Object.keys(parameters);

    const kept = new Set();
    for (const name of names) {
        const choice = CHOICE_FIELD.exec(name);
        if (choice && valueOf(parameters[name]) === 'keep') {
            kept.add(choice[1]);
        }
    }

    for (const name of names) {
        const row = ROW_FIELD.exec(name);
        if (row && kept.has(row[1])) {
            delete parameters[name];
        }
    }
}

/** The form's row inputs that belong to a row set to Keep. */
function keptRowInputs(form) {
    const kept = new Set();
    for (const radio of form.querySelectorAll('input[type="radio"]:checked')) {
        const choice = CHOICE_FIELD.exec(radio.name);
        if (choice && radio.value === 'keep') {
            kept.add(choice[1]);
        }
    }

    return [...form.elements].filter((field) => {
        const row = ROW_FIELD.exec(field.name || '');

        return row !== null && kept.has(row[1]);
    });
}

function onConfigRequest(event) {
    const detail = event.detail;
    if (!detail || !detail.parameters || !document.getElementById(FORM)) {
        return;
    }

    const path = String(detail.path || '');
    if (path !== SCENARIO_PATH && !path.startsWith(SCENARIO_PATH + '?')) {
        return;
    }

    // The form carries the token for its POST; a GET has no use for it and an
    // address is no place for it.
    delete detail.parameters[CSRF_FIELD];

    dropKeptRows(detail.parameters);
}

/**
 * The form's own submit (the button, with htmx not taking it): the fields of
 * Keep rows are disabled while the browser reads the form, so they are not
 * sent, and enabled again straight after.
 */
function onSubmit(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.id !== FORM) {
        return;
    }

    const trimmed = keptRowInputs(form).filter((field) => !field.disabled);
    for (const field of trimmed) {
        field.disabled = true;
    }
    setTimeout(() => {
        for (const field of trimmed) {
            field.disabled = false;
        }
    }, 0);
}

let bound = false;

export function enhanceScenarioForm() {
    if (bound) {
        return;
    }
    bound = true;

    document.addEventListener('htmx:configRequest', onConfigRequest);
    document.addEventListener('submit', onSubmit);
}
