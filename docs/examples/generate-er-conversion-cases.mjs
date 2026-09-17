import { mkdirSync, writeFileSync } from 'node:fs';

const cards = [
    ['zero_one', 'cf-zero-one', '0..1'],
    ['one_one', 'cf-one-one', '1..1'],
    ['zero_many', 'cf-zero-many', '0..N'],
    ['one_many', 'cf-one-many', '1..N'],
];
const entities = [];
const relations = [];

function entity(id, x, y, extra = [], label = id) {
    entities.push({ id, name: label, x, y, attributes: [
        { id: `${id}.id`, name: 'id', type: 'bigint', key: 'PK' }, ...extra,
    ] });
}
function relation(id, from, to, childCard, parentCard, attributes = [], options = {}) {
    const { label = id, ...rest } = options;
    relations.push({ id, name: label, from, fromAttr: '', to, toAttr: `${to}.id`, childCard, parentCard, attributes, ...rest });
}

let index = 0;
for (const [leftKey, leftCard, leftLabel] of cards) {
    for (const [rightKey, rightCard, rightLabel] of cards) {
        for (const withAttribute of [false, true]) {
            const id = `case_${leftKey}_${rightKey}_${withAttribute ? 'attr' : 'plain'}`;
            const number = String(index + 1).padStart(2, '0');
            const x = 40 + (index % 4) * 800;
            const y = 80 + Math.floor(index / 4) * 300;
            entity(`${id}_a`, x, y, [], `A${number} ${leftLabel}`);
            entity(`${id}_b`, x + 430, y, [], `B${number} ${rightLabel}`);
            relation(id, `${id}_a`, `${id}_b`, leftCard, rightCard,
                withAttribute ? [{ id: `${id}.occurred_at`, name: 'occurred_at', type: 'datetime', key: '' }] : [],
                { diamondX: x + 290, diamondY: y + 50, label: `R${number}${withAttribute ? '+' : ''}`, note: `${leftLabel} : ${rightLabel}` });
            index++;
        }
    }
}

let y = 80 + Math.ceil(index / 4) * 300;
entity('employee', 40, y);
relation('manages', 'employee', 'employee', 'cf-zero-many', 'cf-zero-one',
    [{ id: 'manages.since', name: 'since', type: 'date', key: '' }],
    { fromRole: 'subordinate', toRole: 'manager', diamondX: 380, diamondY: y - 100 });
y += 300;
entity('person', 40, y);
relation('follows', 'person', 'person', 'cf-zero-many', 'cf-zero-many',
    [{ id: 'follows.started_at', name: 'started_at', type: 'datetime', key: '' }],
    { fromRole: 'follower', toRole: 'followed', diamondX: 380, diamondY: y - 100 });
y += 300;
entity('spouse', 40, y);
relation('married_to', 'spouse', 'spouse', 'cf-zero-one', 'cf-zero-one',
    [{ id: 'married_to.date', name: 'date', type: 'date', key: '' }],
    { fromRole: 'partner_a', toRole: 'partner_b', diamondX: 380, diamondY: y - 100 });
y += 300;
entity('warehouse', 40, y, [{ id: 'warehouse.code', name: 'code', type: 'varchar', key: 'UQ' }]);
entity('shelf', 500, y);
relation('located_at', 'shelf', 'warehouse', 'cf-zero-many', 'cf-one-one',
    [{ id: 'located_at.since', name: 'since', type: 'date', key: '' }],
    { toAttr: 'warehouse.code' });
y += 300;
entity('document', 40, y, [{ id: 'document.locale', name: 'locale', type: 'varchar', key: 'PK' }]);
entity('revision', 500, y);
relation('has_revision', 'revision', 'document', 'cf-zero-many', 'cf-one-one',
    [{ id: 'has_revision.reason', name: 'reason', type: 'text', key: '' }]);
y += 300;
entity('invoice', 40, y);
entity('invoice_line', 500, y, [{ id: 'invoice_line.line_no', name: 'line_no', type: 'int', key: 'PK' }]);
entities.at(-1).kind = 'weak';
relation('identifies_line', 'invoice_line', 'invoice', 'cf-one-many', 'cf-one-one');
y += 300;
entity('customer', 40, y, [
    { id: 'customer.phone', name: 'phone', type: 'varchar', key: '', multivalued: true },
    { id: 'customer.address', name: 'address', type: 'varchar', key: '', components: [
        { id: 'customer.street', name: 'street', type: 'varchar', key: '' },
        { id: 'customer.city', name: 'city', type: 'varchar', key: '' },
    ] },
]);
y += 300;
entity('supplier', 40, y);
entity('product', 500, y);
entity('project', 960, y);
relation('supplies_for', 'supplier', 'product', 'cf-zero-many', 'cf-zero-many',
    [{ id: 'supplies_for.price', name: 'price', type: 'decimal', key: '' }],
    { kind: 'complex', participants: [
        { entity: 'supplier', role: 'supplier', many: true },
        { entity: 'product', role: 'product', many: true },
        { entity: 'project', role: 'project', many: true },
    ] });
y += 300;
entity('account', 40, y);
entity('profile', 500, y);
relation('has_profile', 'profile', 'account', 'cf-zero-one', 'cf-one-one');
relation('audits_profile', 'profile', 'account', 'cf-zero-many', 'cf-zero-many',
    [{ id: 'audits_profile.comment', name: 'comment', type: 'text', key: '' }],
    { diamondX: 360, diamondY: y + 145 });

const output = { version: 1, title: 'Análise de alternativas ER → relacional', entities, relations };
mkdirSync(new URL('../../public/examples/', import.meta.url), { recursive: true });
writeFileSync(new URL('../../public/examples/er-conversion-cases.json', import.meta.url), JSON.stringify(output, null, 2) + '\n');
