<?php

namespace Tests\Unit\Services;

use App\Services\ErToRelationalTransformer;
use PHPUnit\Framework\TestCase;

class ErToRelationalTransformerTest extends TestCase
{
    private ErToRelationalTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transformer = new ErToRelationalTransformer;
    }

    public function test_strong_entities_become_tables_with_simple_columns(): void
    {
        $result = $this->transformer->transform([
            'entities' => [
                $this->entity('clients', 'Client', [
                    $this->attribute('clients.id', 'clientNo', 'bigint', 'PK'),
                    $this->attribute('clients.name', 'name', 'varchar'),
                ]),
            ],
            'relations' => [],
        ]);

        $client = $this->table($result, 'clients');

        $this->assertSame('Client', $client['name']);
        $this->assertSame(['clientNo', 'name'], array_column($client['columns'], 'name'));
        $this->assertSame('PK', $client['columns'][0]['key']);
    }

    public function test_unconnected_relationship_diamond_is_ignored_until_both_ends_exist(): void
    {
        $result = $this->transformer->transform([
            'entities' => [
                $this->entity('clients', 'Client', [$this->attribute('clients.id', 'clientNo', 'bigint', 'PK')]),
            ],
            'relations' => [[
                'id' => 'draft',
                'name' => 'Relaciona',
                'from' => null,
                'to' => null,
                'childCard' => 'cf-one-many',
                'parentCard' => 'cf-one-one',
            ]],
        ]);

        $this->assertCount(1, $result['tables']);
        $this->assertSame([], $result['foreignKeys']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_one_to_many_copies_parent_pk_and_relationship_attributes_to_child(): void
    {
        $result = $this->transformer->transform([
            'entities' => [
                $this->entity('staff', 'Staff', [$this->attribute('staff.id', 'staffNo', 'bigint', 'PK')]),
                $this->entity('clients', 'Client', [$this->attribute('clients.id', 'clientNo', 'bigint', 'PK')]),
            ],
            'relations' => [[
                'id' => 'registers',
                'name' => 'Registers',
                'from' => 'clients',
                'fromAttr' => '',
                'to' => 'staff',
                'toAttr' => 'staff.id',
                'childCard' => 'cf-zero-many',
                'parentCard' => 'cf-one-one',
                'attributes' => [$this->attribute('registers.date', 'dateRegister', 'date')],
            ]],
        ]);

        $client = $this->table($result, 'clients');
        $staffNo = $this->column($client, 'staffNo');

        $this->assertSame('FK', $staffNo['key']);
        $this->assertSame('staff', $staffNo['references']['table']);
        $this->assertSame('staffNo', $staffNo['references']['column']);
        $this->assertSame('N:1', $staffNo['references']['cardinality']);
        $this->assertSame('cf-zero-many', $staffNo['references']['sourceCard']);
        $this->assertSame('cf-one-one', $staffNo['references']['targetCard']);
        $this->assertSame('dateRegister', $this->column($client, 'dateRegister')['name']);
    }

    public function test_one_to_many_reuses_the_fk_selected_in_the_er_instead_of_duplicating_it(): void
    {
        $result = $this->transformer->transform([
            'entities' => [
                $this->entity('users', 'User', [$this->attribute('users.id', 'id', 'bigint', 'PK')]),
                $this->entity('posts', 'Post', [
                    $this->attribute('posts.id', 'id', 'bigint', 'PK'),
                    $this->attribute('posts.user_id', 'user_id', 'bigint', 'FK'),
                ]),
            ],
            'relations' => [[
                'id' => 'writes', 'name' => 'Writes',
                'from' => 'posts', 'fromAttr' => 'posts.user_id',
                'to' => 'users', 'toAttr' => 'users.id',
                'childCard' => 'cf-zero-many', 'parentCard' => 'cf-one-one',
            ]],
        ]);

        $posts = $this->table($result, 'posts');

        $this->assertSame(['id', 'user_id'], array_column($posts['columns'], 'name'));
        $this->assertSame('FK', $this->column($posts, 'user_id')['key']);
        $this->assertSame('users', $this->column($posts, 'user_id')['references']['table']);
        $this->assertNull(collect($posts['columns'])->firstWhere('name', 'usersId'));
    }

    public function test_a_selected_unique_key_can_be_the_referenced_key(): void
    {
        $result = $this->transformer->transform([
            'entities' => [
                $this->entity('users', 'User', [$this->attribute('users.email', 'email', 'varchar', 'UQ')]),
                $this->entity('profiles', 'Profile', [
                    $this->attribute('profiles.id', 'id', 'bigint', 'PK'),
                    $this->attribute('profiles.user_email', 'user_email', 'varchar', 'FK'),
                ]),
            ],
            'relations' => [[
                'id' => 'owns', 'name' => 'Owns',
                'from' => 'profiles', 'fromAttr' => 'profiles.user_email',
                'to' => 'users', 'toAttr' => 'users.email',
                'childCard' => 'cf-zero-many', 'parentCard' => 'cf-one-one',
            ]],
        ]);

        $fk = $this->column($this->table($result, 'profiles'), 'user_email');

        $this->assertSame('email', $fk['references']['column']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_one_to_many_does_not_turn_the_child_primary_key_into_the_foreign_key(): void
    {
        $result = $this->transformer->transform([
            'entities' => [
                $this->entity('users', 'User', [$this->attribute('users.id', 'id', 'bigint', 'PK')]),
                $this->entity('posts', 'Post', [$this->attribute('posts.id', 'id', 'bigint', 'PK')]),
            ],
            'relations' => [[
                'id' => 'writes', 'name' => 'Writes',
                'from' => 'posts', 'fromAttr' => 'posts.id',
                'to' => 'users', 'toAttr' => 'users.id',
                'childCard' => 'cf-zero-many', 'parentCard' => 'cf-one-one',
            ]],
        ]);

        $posts = $this->table($result, 'posts');

        $this->assertSame(['id'], $posts['primaryKey']);
        $this->assertSame('PK', $this->column($posts, 'id')['key']);
        $this->assertSame('FK', $this->column($posts, 'userId')['key']);
    }

    public function test_many_to_many_creates_an_associative_table_with_composite_pk(): void
    {
        $result = $this->transformer->transform([
            'entities' => [
                $this->entity('clients', 'Client', [$this->attribute('clients.id', 'clientNo', 'bigint', 'PK')]),
                $this->entity('properties', 'Property', [$this->attribute('properties.id', 'propertyNo', 'bigint', 'PK')]),
            ],
            'relations' => [[
                'id' => 'views',
                'name' => 'Viewing',
                'from' => 'clients',
                'to' => 'properties',
                'fromAttr' => 'clients.id',
                'toAttr' => 'properties.id',
                'childCard' => 'cf-zero-many',
                'parentCard' => 'cf-zero-many',
                'attributes' => [$this->attribute('views.date', 'dateView', 'date')],
            ]],
        ]);

        $viewing = $this->table($result, 'relation_views');

        $this->assertSame('Viewing', $viewing['name']);
        $this->assertSame(['clientNo', 'propertyNo'], $viewing['primaryKey']);
        $this->assertSame('PK/FK', $this->column($viewing, 'clientNo')['key']);
        $this->assertSame('PK/FK', $this->column($viewing, 'propertyNo')['key']);
        $this->assertFalse($this->column($viewing, 'clientNo')['nullable']);
        $this->assertFalse($this->column($viewing, 'propertyNo')['nullable']);
        $this->assertSame(['N:1', 'N:1'], array_column($result['foreignKeys'], 'cardinality'));
        $this->assertSame('dateView', $this->column($viewing, 'dateView')['name']);
    }

    public function test_many_to_many_removes_a_dedicated_fk_left_from_a_previous_one_to_many_mapping(): void
    {
        $result = $this->transformer->transform([
            'entities' => [
                $this->entity('posts', 'Post', [
                    $this->attribute('posts.id', 'id', 'bigint', 'PK'),
                    $this->attribute('posts.user_id', 'user_id', 'bigint', 'FK'),
                ]),
                $this->entity('users', 'User', [$this->attribute('users.id', 'id', 'bigint', 'PK')]),
            ],
            'relations' => [[
                'id' => 'likes', 'name' => 'Likes',
                'from' => 'posts', 'fromAttr' => 'posts.user_id',
                'to' => 'users', 'toAttr' => 'users.id',
                'childCard' => 'cf-zero-many', 'parentCard' => 'cf-zero-many',
            ]],
        ]);

        $posts = $this->table($result, 'posts');
        $likes = $this->table($result, 'relation_likes');

        $this->assertSame(['id'], array_column($posts['columns'], 'name'));
        $this->assertSame(['id', 'userId'], $likes['primaryKey']);
    }

    public function test_mandatory_one_to_one_preserves_tables_and_migrates_the_key(): void
    {
        $result = $this->transformer->transform([
            'entities' => [
                $this->entity('clients', 'Client', [$this->attribute('clients.id', 'clientNo', 'bigint', 'PK')]),
                $this->entity('preferences', 'Preference', [
                    $this->attribute('preferences.type', 'prefType', 'varchar'),
                    $this->attribute('preferences.rent', 'maxRent', 'decimal'),
                ], 'weak'),
            ],
            'relations' => [[
                'id' => 'states',
                'name' => 'States',
                'from' => 'preferences',
                'to' => 'clients',
                'fromAttr' => '',
                'toAttr' => 'clients.id',
                'childCard' => 'cf-one-one',
                'parentCard' => 'cf-one-one',
                'attributes' => [$this->attribute('states.date', 'dateStated', 'date')],
            ]],
        ]);

        $this->assertCount(2, $result['tables']);
        $preference = $this->table($result, 'preferences');
        $this->assertSame(['prefType', 'maxRent', 'clientNo', 'dateStated'], array_column($preference['columns'], 'name'));
        $this->assertSame(['clientNo'], $preference['primaryKey']);
        $this->assertFalse($this->column($preference, 'clientNo')['nullable']);
    }

    public function test_recursive_one_to_many_adds_a_renamed_self_foreign_key(): void
    {
        $result = $this->transformer->transform([
            'entities' => [
                $this->entity('employees', 'Employee', [$this->attribute('employees.id', 'employeeNo', 'bigint', 'PK')]),
            ],
            'relations' => [[
                'id' => 'supervises',
                'name' => 'Supervises',
                'from' => 'employees',
                'to' => 'employees',
                'fromAttr' => '',
                'toAttr' => 'employees.id',
                'childCard' => 'cf-zero-many',
                'parentCard' => 'cf-zero-one',
                'fromRole' => 'subordinate',
                'toRole' => 'supervisor',
            ]],
        ]);

        $employee = $this->table($result, 'employees');
        $supervisor = $this->column($employee, 'supervisorEmployeeNo');

        $this->assertSame('FK', $supervisor['key']);
        $this->assertSame('employees', $supervisor['references']['table']);
    }

    public function test_recursive_one_to_many_uses_the_role_on_the_one_side_in_either_direction(): void
    {
        foreach ([
            ['cf-zero-many', 'cf-zero-one'],
            ['cf-zero-one', 'cf-zero-many'],
        ] as [$fromCard, $toCard]) {
            $result = $this->transformer->transform([
                'entities' => [
                    $this->entity('employees', 'Employee', [$this->attribute('employees.id', 'employeeNo', 'bigint', 'PK')]),
                ],
                'relations' => [[
                    'id' => 'supervises', 'name' => 'Supervises',
                    'from' => 'employees', 'to' => 'employees',
                    'childCard' => $fromCard, 'parentCard' => $toCard,
                    'fromRole' => $fromCard === 'cf-zero-one' ? 'supervisor' : 'subordinate',
                    'toRole' => $toCard === 'cf-zero-one' ? 'supervisor' : 'subordinate',
                ]],
            ]);

            $supervisor = $this->column($this->table($result, 'employees'), 'supervisorEmployeeNo');
            $this->assertTrue($supervisor['nullable'], "$fromCard:$toCard");
            $this->assertSame('employees', $supervisor['references']['table']);
        }
    }

    public function test_recursive_many_to_many_uses_both_roles_in_the_associative_key(): void
    {
        $result = $this->transformer->transform([
            'entities' => [
                $this->entity('people', 'Person', [$this->attribute('people.id', 'personNo', 'bigint', 'PK')]),
            ],
            'relations' => [[
                'id' => 'follows', 'name' => 'Follows',
                'from' => 'people', 'to' => 'people',
                'childCard' => 'cf-zero-many', 'parentCard' => 'cf-zero-many',
                'fromRole' => 'follower', 'toRole' => 'followed',
            ]],
        ]);

        $follows = $this->table($result, 'relation_follows');

        $this->assertSame('Follows', $follows['name']);
        $this->assertSame(['followerPersonNo', 'followedPersonNo'], $follows['primaryKey']);
        $this->assertSame('PK/FK', $this->column($follows, 'followerPersonNo')['key']);
        $this->assertSame('PK/FK', $this->column($follows, 'followedPersonNo')['key']);
    }

    public function test_multivalued_attribute_creates_a_dependent_table(): void
    {
        $result = $this->transformer->transform([
            'entities' => [
                $this->entity('branches', 'Branch', [
                    $this->attribute('branches.id', 'branchNo', 'bigint', 'PK'),
                    $this->attribute('branches.phone', 'telNo', 'varchar', '', true),
                ]),
            ],
            'relations' => [],
        ]);

        $telephone = $this->table($result, 'branches_tel_no');

        $this->assertSame(['telNo', 'branchNo'], $telephone['primaryKey']);
        $this->assertSame('branches', $this->column($telephone, 'branchNo')['references']['table']);
    }

    public function test_optional_recursive_one_to_one_creates_its_own_relation(): void
    {
        $result = $this->transformer->transform([
            'entities' => [
                $this->entity('people', 'Person', [$this->attribute('people.id', 'personNo', 'bigint', 'PK')]),
            ],
            'relations' => [[
                'id' => 'marriage',
                'name' => 'Marriage',
                'from' => 'people',
                'to' => 'people',
                'fromAttr' => 'people.id',
                'toAttr' => 'people.id',
                'childCard' => 'cf-zero-one',
                'parentCard' => 'cf-zero-one',
                'fromRole' => 'spouseA',
                'toRole' => 'spouseB',
            ]],
        ]);

        $marriage = $this->table($result, 'relation_marriage');

        $this->assertSame('Marriage', $marriage['name']);
        $this->assertSame(['spouseAPersonNo'], $marriage['primaryKey']);
        $this->assertSame('PK/FK', $this->column($marriage, 'spouseAPersonNo')['key']);
        $this->assertSame('UQ/FK', $this->column($marriage, 'spouseBPersonNo')['key']);
        $this->assertFalse($this->column($marriage, 'spouseAPersonNo')['nullable']);
        $this->assertFalse($this->column($marriage, 'spouseBPersonNo')['nullable']);
        $this->assertSame('people', $this->column($marriage, 'spouseAPersonNo')['references']['table']);
        $this->assertSame('people', $this->column($marriage, 'spouseBPersonNo')['references']['table']);
    }

    public function test_complex_relationship_copies_every_participant_key(): void
    {
        $result = $this->transformer->transform([
            'entities' => [
                $this->entity('suppliers', 'Supplier', [$this->attribute('suppliers.id', 'supplierNo', 'bigint', 'PK')]),
                $this->entity('parts', 'Part', [$this->attribute('parts.id', 'partNo', 'bigint', 'PK')]),
                $this->entity('projects', 'Project', [$this->attribute('projects.id', 'projectNo', 'bigint', 'PK')]),
            ],
            'relations' => [[
                'id' => 'supplies',
                'name' => 'Supply',
                'kind' => 'complex',
                'from' => 'suppliers',
                'to' => 'parts',
                'participants' => [
                    ['entity' => 'suppliers', 'many' => true],
                    ['entity' => 'parts', 'many' => true],
                    ['entity' => 'projects', 'many' => true],
                ],
                'attributes' => [$this->attribute('supplies.qty', 'quantity', 'integer')],
            ]],
        ]);

        $supply = $this->table($result, 'relation_supplies');

        $this->assertSame(['supplierNo', 'partNo', 'projectNo'], $supply['primaryKey']);
        $this->assertSame('quantity', $this->column($supply, 'quantity')['name']);
    }

    public function test_binary_cardinalities_choose_the_fk_holder_and_nullability_in_both_directions(): void
    {
        foreach ([
            ['cf-one-one', 'cf-one-one', 'a', false, 'UQ/FK'],
            ['cf-zero-one', 'cf-one-one', 'a', false, 'UQ/FK'],
            ['cf-one-one', 'cf-zero-one', 'b', false, 'UQ/FK'],
            ['cf-zero-one', 'cf-zero-one', 'a', true, 'UQ/FK'],
            ['cf-zero-many', 'cf-one-one', 'a', false, 'FK'],
            ['cf-one-one', 'cf-zero-many', 'b', false, 'FK'],
            ['cf-one-many', 'cf-zero-one', 'a', true, 'FK'],
            ['cf-zero-one', 'cf-one-many', 'b', true, 'FK'],
        ] as [$from, $to, $holder, $nullable, $key]) {
            $result = $this->transformer->transform([
                'entities' => [
                    $this->entity('a', 'A', [$this->attribute('a.id', 'aId', 'bigint', 'PK')]),
                    $this->entity('b', 'B', [$this->attribute('b.id', 'bId', 'bigint', 'PK')]),
                ],
                'relations' => [[
                    'id' => 'link', 'name' => 'Link', 'from' => 'a', 'to' => 'b',
                    'childCard' => $from, 'parentCard' => $to,
                    'attributes' => [$this->attribute('link.date', 'linkedAt', 'date')],
                ]],
            ]);
            $this->assertCount(2, $result['tables']);
            $this->assertCount(1, $result['foreignKeys']);
            $table = $this->table($result, $holder);
            $column = $this->column($table, $holder === 'a' ? 'bId' : 'aId');
            $this->assertSame($nullable, $column['nullable'], "$from:$to");
            $this->assertSame($key, $column['key'], "$from:$to");
            $relationshipAttribute = $this->column($table, 'linkedAt');
            $this->assertSame('linkedAt', $relationshipAttribute['name']);
            $this->assertSame($nullable, $relationshipAttribute['nullable'], "$from:$to");
        }
    }

    private function entity(string $id, string $name, array $attributes, string $kind = 'strong'): array
    {
        return compact('id', 'name', 'attributes', 'kind') + ['x' => 0, 'y' => 0];
    }

    private function attribute(string $id, string $name, string $type, string $key = '', bool $multivalued = false): array
    {
        return compact('id', 'name', 'type', 'key', 'multivalued');
    }

    private function table(array $result, string $id): array
    {
        $table = collect($result['tables'])->firstWhere('id', $id);
        $this->assertNotNull($table, "Tabela {$id} não foi criada.");

        return $table;
    }

    private function column(array $table, string $name): array
    {
        $column = collect($table['columns'])->firstWhere('name', $name);
        $this->assertNotNull($column, "Coluna {$name} não foi criada.");

        return $column;
    }
}
