import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToString } from 'react-dom/server';
import { useRecordNavigation } from '../dist/components/use-record-navigation.js';

function navigation(options = {}) {
    const visits = [];
    let requests = 0;
    let result;
    function Probe() {
        result = useRecordNavigation({
            items: [{ id: 'c' }, { id: 'a' }, { id: 'b' }],
            currentId: 'a',
            getId: (item) => item.id,
            onNavigate: (id) => visits.push(id),
            scopeKey: 'filtered:name-desc',
            loadMore: () => requests++,
            ...options,
        });

        return null;
    }
    renderToString(createElement(Probe));

    return { result, visits, requests: () => requests };
}
const middle = navigation();
assert.equal(middle.result.previousDisabled, false);
assert.equal(middle.result.nextDisabled, false);
middle.result.previous();
middle.result.next();
assert.deepEqual(middle.visits, ['c', 'b']);
const first = navigation({ currentId: 'c' });
assert.equal(first.result.previousDisabled, true);
first.result.previous();
assert.deepEqual(first.visits, []);
const last = navigation({ currentId: 'b', hasMore: true });
assert.equal(last.result.nextDisabled, false);
last.result.next();
last.result.next();
assert.equal(last.requests(), 1);
assert.deepEqual(last.visits, []);

const failedBatch = navigation({
    currentId: 'b',
    hasMore: true,
    loadMore: () => false,
});
failedBatch.result.next();
failedBatch.result.previous();
assert.deepEqual(failedBatch.visits, ['a']);

for (const options of [
    { disabled: true },
    { loading: true },
    { currentId: 'outside-filter' },
    { currentId: null },
]) {
    const blocked = navigation(options);
    assert.equal(blocked.result.previousDisabled, true);
    assert.equal(blocked.result.nextDisabled, true);
    blocked.result.previous();
    blocked.result.next();
    assert.deepEqual(blocked.visits, []);
    assert.equal(blocked.requests(), 0);
}

assert.equal(
    navigation({ currentId: 'b', hasMore: false }).result.nextDisabled,
    true,
);
console.log(
    'Record navigation: filtered order, batch boundary, unknown record, loading and draft guards passed.',
);
