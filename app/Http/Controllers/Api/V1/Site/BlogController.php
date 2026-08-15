<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\CommentStatus;
use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\TeamMember;
use App\Support\Content\RichText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The blog and the team page, for anyone who is not signed in.
 *
 * Only published articles ever appear here: the query starts from the
 * published scope rather than filtering afterwards, so a draft cannot leak
 * through a filter somebody forgets to apply.
 */
class BlogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'category' => ['sometimes', 'string', 'max:140'],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);

        $query = Post::query()->published()->with('category:id,name,slug', 'author:id,name');

        if ($slug = $filters['category'] ?? null) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $slug));
        }

        if ($search = $filters['search'] ?? null) {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")
                ->orWhere('excerpt', 'like', "%{$search}%"));
        }

        $posts = $query->paginate(9);

        return response()->json([
            'data' => array_map(fn (Post $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'slug' => $p->slug,
                'excerpt' => $p->excerpt,
                'cover_image' => $p->cover_image,
                'category' => $p->category?->name,
                'category_slug' => $p->category?->slug,
                'author' => $p->author?->name,
                'published_at' => $p->published_at?->toIso8601String(),
                'reading_minutes' => $p->readingMinutes(),
            ], $posts->items()),
            'meta' => [
                'total' => $posts->total(),
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
            ],
            'categories' => PostCategory::query()
                ->where('status', true)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'slug']),
        ]);
    }

    /**
     * One article, by its web address.
     */
    public function show(string $slug): JsonResponse
    {
        $post = Post::query()->published()
            ->where('slug', $slug)
            ->with('category:id,name,slug', 'author:id,name', 'tags:id,name,slug')
            ->firstOrFail();

        // Counted without touching updated_at: a read is not an edit, and
        // bumping the timestamp would make every article look freshly changed.
        DB::table('posts')->where('id', $post->id)->increment('view_count');

        return response()->json([
            'data' => [
                'id' => $post->id,
                'title' => $post->title,
                'slug' => $post->slug,
                'excerpt' => $post->excerpt,
                // Already reduced to a short whitelist when it was saved, so
                // this is safe to render as markup.
                'body' => $post->body,
                'sections' => RichText::sections($post->body),
                'cover_image' => $post->cover_image,
                'category' => $post->category ? ['name' => $post->category->name, 'slug' => $post->category->slug] : null,
                'tags' => $post->tags->map(fn ($t) => ['name' => $t->name, 'slug' => $t->slug]),
                'author' => $post->author?->name,
                'published_at' => $post->published_at?->toIso8601String(),
                'reading_minutes' => $post->readingMinutes(),
                'comments_open' => $post->comments_open,
                'comments' => $post->comments()->approved()->oldest('created_at')->get()
                    ->map(fn (Comment $c) => [
                        'id' => $c->id,
                        'author_name' => $c->author_name,
                        'body' => $c->body,
                        'created_at' => $c->created_at?->toIso8601String(),
                    ]),
            ],
        ]);
    }

    /**
     * Leave a comment.
     *
     * Nothing appears until it is approved. The reply says so plainly rather
     * than showing the comment and quietly hiding it from everyone else, which
     * leads people to post again thinking it failed.
     */
    public function comment(Request $request, string $slug): JsonResponse
    {
        $post = Post::query()->published()->where('slug', $slug)->firstOrFail();

        abort_unless($post->comments_open, 422, 'Comments are closed on this article.');

        $data = $request->validate([
            'author_name' => ['required', 'string', 'max:120'],
            'author_email' => ['nullable', 'email', 'max:191'],
            'body' => ['required', 'string', 'min:2', 'max:2000'],
        ]);

        Comment::create($data + [
            'post_id' => $post->id,
            'user_id' => $request->user()?->id,
            'status' => CommentStatus::Pending,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Thank you. Your comment will appear once it has been checked.',
        ], 201);
    }

    /**
     * The people behind the business.
     */
    public function team(): JsonResponse
    {
        return response()->json([
            'data' => TeamMember::query()->live()->get()->map(fn (TeamMember $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'title' => $m->title,
                'bio' => $m->bio,
                'photo' => $m->photo_path,
            ]),
        ]);
    }
}
