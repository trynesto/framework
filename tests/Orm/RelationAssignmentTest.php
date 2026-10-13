<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\JoinColumn;
use Nesto\Orm\Attributes\OneToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;
use Nesto\Orm\DataSource;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\OrmException;
use Nesto\Orm\Repository;
use Nesto\Tests\Orm\Drivers\Fixtures\Member;
use Nesto\Tests\Orm\Drivers\Fixtures\Team;
use PHPUnit\Framework\TestCase;

/** Assigning an id / entity / ['id' => …] to an owning to-one relation property sets the foreign key. */
final class RelationAssignmentTest extends TestCase
{
    private DataSource $ds;
    private Repository $teams;
    private Repository $members;

    protected function setUp(): void
    {
        MetadataRegistry::clear();
        $this->ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [Team::class, Member::class, AssignPassport::class, AssignHolder::class], 'synchronize' => true]);
        $this->teams = $this->ds->getRepository(Team::class);
        $this->members = $this->ds->getRepository(Member::class);
        $this->teams->save([$this->teams->create(['name' => 'Red']), $this->teams->create(['name' => 'Blue'])]);
    }

    public function testScalarIdOnNullableManyToOneSetsTheForeignKey(): void
    {
        $member = $this->members->save($this->members->create(['name' => 'Ada', 'team' => 2]));   // Member::$team = null by default
        $this->assertSame(2, $this->teamIdOf($member->id));
        $this->assertSame('Blue', $this->members->findOneOrFail(['where' => ['id' => $member->id], 'relations' => ['team']])->team->name);
    }

    public function testNumericStringIdIsAccepted(): void
    {
        $member = $this->members->save($this->members->create(['name' => 'Ada', 'team' => '1']));
        $this->assertSame(1, $this->teamIdOf($member->id));
    }

    public function testArrayWithPrimaryKeySetsTheForeignKeyWithoutInsertingTheTarget(): void
    {
        $member = $this->members->save($this->members->create(['name' => 'Ada', 'team' => ['id' => 2]]));
        $this->assertSame(2, $this->teamIdOf($member->id));
        $this->assertSame(2, $this->teams->count());
    }

    public function testEntityInstanceSetsTheForeignKey(): void
    {
        $blue = $this->teams->findByIdOrFail(2);
        $member = $this->members->save($this->members->create(['name' => 'Ada', 'team' => $blue]));
        $this->assertSame(2, $this->teamIdOf($member->id));
    }

    public function testMergeWithScalarIdRepointsALoadedRelation(): void
    {
        $member = $this->members->save($this->members->create(['name' => 'Ada', 'team' => 1]));
        $loaded = $this->members->findOneOrFail(['where' => ['id' => $member->id], 'relations' => ['team']]);
        $this->members->save($this->members->merge($loaded, ['team' => 2]));
        $this->assertSame(2, $this->teamIdOf($member->id));

        $this->members->save($this->members->merge($loaded, ['team' => null]));
        $this->assertNull($this->teamIdOf($member->id));
    }

    public function testScalarIdOnNullableOwningOneToOne(): void
    {
        $this->members->save([$this->members->create(['name' => 'Ada']), $this->members->create(['name' => 'Bob'])]);
        $passports = $this->ds->getRepository(AssignPassport::class);
        $passport = $passports->save($passports->create(['code' => 'X1', 'holder' => 2]));
        $this->assertSame(2, (int) $this->ds->getConnection()->fetchColumn('SELECT holder_id FROM assign_passports WHERE id = ?', [$passport->id]));
    }

    public function testBareIdsInAToManyRelationBecomeStubs(): void
    {
        $ada = $this->members->save($this->members->create(['name' => 'Ada']));
        $this->teams->save($this->teams->merge($this->teams->findByIdOrFail(2), ['members' => [$ada->id]]));
        $this->assertSame(2, $this->teamIdOf($ada->id));
        $this->assertSame('Ada', $this->members->findByIdOrFail($ada->id)->name);
    }

    public function testBareIdOnAnInverseToOneIsRejected(): void
    {
        $this->expectException(OrmException::class);
        $this->expectExceptionMessage('inverse side');
        $this->ds->getRepository(AssignHolder::class)->create(['passport' => 1]);
    }

    private function teamIdOf(int $memberId): ?int
    {
        $value = $this->ds->getConnection()->fetchColumn('SELECT team_id FROM members WHERE id = ?', [$memberId]);

        return $value === null ? null : (int) $value;
    }
}

#[Entity('assign_passports')]
final class AssignPassport
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $code;

    #[OneToOne(Member::class)]
    #[JoinColumn(name: 'holder_id')]
    public ?Member $holder = null;
}

#[Entity('assign_holders')]
final class AssignHolder
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[OneToOne(AssignPassport::class, inverseSide: 'holder')]
    public ?AssignPassport $passport = null;
}
