const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const components = new Map();
const elements = [];
const blocks = [];
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../src/Resources/app/administration/src/module/personalizations.js'), 'utf8'), {
    Shopware: {
        Component: { register: (name, definition) => components.set(name, definition) },
        Mixin: { getByName: () => ({}) },
        Service: () => ({ registerCmsElement: value => elements.push(value), registerCmsBlock: value => blocks.push(value) }),
    },
});
const editor = components.get('le-personalization-rules');
const rules = [{ key: 'personalization-2', public_id: 'test-1', enabled: true }];
assert.equal(editor.computed.rules.call({ value: rules }), rules, 'Shopware value prop');
assert.equal(editor.computed.rules.call({ value: undefined, modelValue: rules }), rules, 'Model value fallback');
assert.equal(editor.computed.rules.call({ value: null }).length, 0);
const emitted = [];
const instance = { rules, $emit: (event, value) => emitted.push({ event, value }) };
Object.assign(instance, editor.methods);
instance.add();
assert.equal(emitted[0].value[1].key, 'personalization-3', 'Add never duplicates an existing key');
assert.equal(emitted[0].value[1].enabled, false, 'New rules start disabled');
assert.equal(emitted.map(item => item.event).join(','), 'change,update:value,update:modelValue', 'Both supported event contracts emitted');
emitted.length = 0;
instance.update(0, 'public_id', 'other');
assert.equal(emitted[0].value[0].public_id, 'other');
assert.equal(rules[0].public_id, 'test-1', 'Props never mutated in place');
assert.ok(editor.template.includes(':ids="rule.pages"') && editor.template.includes(':value="rule.pages"'), '6.5 and 6.7 selector props');
assert.ok(editor.template.includes('@change="update(index, \'pages\', $event)"') && editor.template.includes('@update:value="update(index, \'pages\', $event)"'), '6.5 and 6.7 selector events');
assert.equal(elements.length, 1);
assert.equal(elements[0].name, 'le-personalization');
assert.equal(blocks[0].slots.content, 'le-personalization');
console.log('Personalization administration rules, immutable updates and CMS registration checks passed');
