<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\Mapping\RelationType;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\OrmException;
use Nesto\Tests\Orm\Fixtures\Post;
use Nesto\Tests\Orm\Fixtures\PostStatus;
use Nesto\Tests\Orm\Fixtures\Profile;
use Nesto\Tests\Orm\Fixtures\Tag;
use Nesto\Tests\Orm\Fixtures\User;
use PHPUnit\Framework\TestCase;

final class MetadataTest extends TestCase
{
    protected function setUp(): void
    {
        MetadataRegistry::clear();
    }

    public function testTableNamesAndColumns(): void
    {
        $user = MetadataRegistry::get(User::class);
        $this->assertSame('users', $user->table);
        $this->assertSame('id', $user->primary->name);
        $this->assertTrue($user->primary->generated);
        $this->assertSame('created_at', $user->column('createdAt')->name);
        $this->assertSame('datetime', $user->column('createdAt')->type);
        $this->assertSame('json', $user->column('settings')->type);
        $this->assertTrue($user->column('settings')->nullable);
        $this->assertSame('bool', $user->column('active')->type);
        $this->assertTrue($user->column('email')->unique);
        $this->assertSame('createdAt', $user->createdAt);
        $this->assertSame('updatedAt', $user->updatedAt);
        $this->assertSame('deletedAt', $user->deletedAt);
        $this->assertSame([['columns' => ['name'], 'unique' => false, 'name' => null, 'where' => null]], $user->indexes);

        $post = MetadataRegistry::get(Post::class);
        $this->assertSame('posts', $post->table);
        $this->assertSame('string', $post->column('status')->type);
        $this->assertSame(PostStatus::class, $post->column('status')->enumClass);
        $this->assertSame('decimal', $post->column('price')->type);
        $this->assertSame(8, $post->column('price')->precision);
        $this->assertSame('user_id', $post->columnName('user'));
        $this->assertTrue($post->columns['user_id']->isVirtual());
        $this->assertSame('user', $post->columns['user_id']->relation);

        $this->assertSame('tags', MetadataRegistry::get(Tag::class)->table);
        $this->assertSame('uuid', MetadataRegistry::get(Profile::class)->primary->type);
    }

    public function testRelations(): void
    {
        $post = MetadataRegistry::get(Post::class);
        $user = $post->relation('user');
        $this->assertSame(RelationType::ManyToOne, $user->type);
        $this->assertSame(User::class, $user->target);
        $this->assertSame('user_id', $user->joinColumn);
        $this->assertTrue($user->owning);

        $tags = $post->relation('tags');
        $this->assertSame(RelationType::ManyToMany, $tags->type);
        $this->assertSame('post_tags', $tags->joinTable);
        $this->assertSame('post_id', $tags->joinTableColumn);
        $this->assertSame('tag_id', $tags->inverseJoinColumn);
        $this->assertTrue($tags->owning);
        $this->assertFalse(MetadataRegistry::get(Tag::class)->relation('posts')->owning);

        $posts = MetadataRegistry::get(User::class)->relation('posts');
        $this->assertSame(RelationType::OneToMany, $posts->type);
        $this->assertSame('user', $posts->inverseSide);
        $this->assertTrue($posts->isToMany());

        $profile = MetadataRegistry::get(Profile::class)->relation('user');
        $this->assertSame(RelationType::OneToOne, $profile->type);
        $this->assertTrue($profile->holdsForeignKey());
        $this->assertFalse(MetadataRegistry::get(User::class)->relation('profile')->holdsForeignKey());
    }

    public function testNonEntityThrows(): void
    {
        $this->expectException(OrmException::class);
        MetadataRegistry::get(\stdClass::class);
    }
}
