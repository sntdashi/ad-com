import { test } from 'node:test';
import assert from 'node:assert/strict';
import { normalizeApiUrl, mergeMessages } from './connection.ts';

test('preview accepts local origins and normalizes the API suffix', () => {
    assert.equal(
        normalizeApiUrl('http://10.0.2.2:8000/', true),
        'http://10.0.2.2:8000/api/v1',
    );
    assert.equal(
        normalizeApiUrl('https://adcom.example/api/v1/', true),
        'https://adcom.example/api/v1',
    );
});
test('production refuses insecure connections', () => {
    assert.throws(() => normalizeApiUrl('http://adcom.example', false));
    assert.equal(
        normalizeApiUrl('https://adcom.example', false),
        'https://adcom.example/api/v1',
    );
});
test('rejects credential-bearing URLs, foreign paths and query strings', () => {
    for (const input of [
        'javascript:alert(1)',
        'https://user:password@adcom.example',
        'https://adcom.example/private',
        'https://adcom.example?key=x',
        'https://adcom.example/#x',
    ]) {
        assert.throws(() => normalizeApiUrl(input, true));
    }
});
test('chat polling keeps older pages and deduplicates incoming messages in order', () => {
    assert.deepEqual(
        mergeMessages(
            [
                { id: 2, body: 'old' },
                { id: 1, body: 'first' },
            ],
            [
                { id: 2, body: 'updated' },
                { id: 3, body: 'third' },
            ],
        ),
        [
            { id: 1, body: 'first' },
            { id: 2, body: 'updated' },
            { id: 3, body: 'third' },
        ],
    );
});
