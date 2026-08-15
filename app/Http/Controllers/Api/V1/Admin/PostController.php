<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CommentStatus;
use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Post;
use App\Support\Content\RichText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Carbon;

/**
 * Writing and moderating the blog.
 *
 * The website is one thing across all franchises, so this sits behind the same
 * ability as the rest of the site content rather than being something each
 * branch does for itself.
 */
class PostController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['can:manage.master'];
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'state' => ['sometimes', 'string', 'in:published,draft,scheduled'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Post::query()->with('category', 'author')->withCount([
            'comments',
            // Surfaced in the list so a queue of comments waiting on somebody
            // is visible without opening every article.
            'comments as pending_comments_count' => fn ($q) => $q->where('status', CommentStatus::Pending),
        ]);

        match ($filters['state'] ?? null) {
            'published' => $query->published(),
            'draft' => $query->draft(),
            'scheduled' => $query->scheduled(),
            default => $query->orderByRaw('published_at IS NULL DESC')->orderByDesc('published_at'),
        };

        if ($search = $filters['search'] ?? null) {
            $query->where('title', 'like', "%{$search}%");
        }

        $posts = $query->paginate($filters['per_page'] ?? 20);

        return response()->json([
            'data' => array_map(fn (Post $p) => $this->present($p), $posts->items()),
            'meta' => [
                'total' => $posts->total(),
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'pending_comments' => Comment::query()->pending()->count(),
            ],
        ]);
    }

    public function show(Post $post): JsonResponse
    {
        return response()->json([
            'data' => $this->present($post->load('category', 'author', 'tags'), full: true),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $post = Post::create($data + ['author_id' => $request->user()->id]);
        $post->tags()->sync($request->input('tag_ids', []));

        return response()->json(['data' => $this->present($post->load('category', 'author', 'tags'), full: true)], 201);
    }

    public function update(Request $request, Post $post): JsonResponse
    {
        $post->update($this->validated($request));

        if ($request->has('tag_ids')) {
            $post->tags()->sync($request->input('tag_ids', []));
        }

        return response()->json(['data' => $this->present($post->fresh()->load('category', 'author', 'tags'), full: true)]);
    }

    public function destroy(Post $post): JsonResponse
    {
        // Soft deleted, so a post taken down by mistake can come back and its
        // web address is not immediately reused by something else.
        $post->delete();

        return response()->json(['message' => 'Article removed.']);
    }

    /**
     * Publish now, or take it back to a draft.
     *
     * A separate endpoint rather than a field on update, because publishing is
     * a decision and should not happen as a side effect of fixing a typo.
     */
    public function publish(Request $request, Post $post): JsonResponse
    {
        $data = $request->validate([
            'publish' => ['required', 'boolean'],
            'at' => ['sometimes', 'nullable', 'date'],
        ]);

        $post->forceFill([
            'published_at' => $data['publish']
                ? (isset($data['at']) ? Carbon::parse($data['at']) : Carbon::now())
                : null,
        ])->save();

        return response()->json(['data' => $this->present($post->fresh()->load('category', 'author'))]);
    }

    // -------------------------------------------------------------- comments

    public function comments(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string', 'in:pending,approved,spam'],
        ]);

        $comments = Comment::query()
            ->with('post:id,title,slug')
            ->when($filters['status'] ?? 'pending', fn ($q, $s) => $q->where('status', $s))
            ->latest('created_at')
            ->paginate(30);

        return response()->json([
            'data' => array_map(fn (Comment $c) => [
                'id' => $c->id,
                'author_name' => $c->author_name,
                'body' => $c->body,
                'status' => ['value' => $c->status->value, 'label' => $c->status->label()],
                'post' => $c->post ? ['id' => $c->post->id, 'title' => $c->post->title] : null,
                'created_at' => $c->created_at?->toIso8601String(),
            ], $comments->items()),
            'meta' => [
                'total' => $comments->total(),
                'current_page' => $comments->currentPage(),
                'last_page' => $comments->lastPage(),
                'pending' => Comment::query()->pending()->count(),
            ],
        ]);
    }

    public function moderate(Request $request, Comment $comment): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:approved,spam,pending'],
        ]);

        $comment->forceFill([
            'status' => CommentStatus::from($data['status']),
            // Who decided, and when. A comment appearing on the site is a
            // publishing decision and somebody owns it.
            'moderated_by' => $request->user()->id,
            'moderated_at' => now(),
        ])->save();

        return response()->json(['message' => 'Comment '.$comment->status->label().'.']);
    }

    // --------------------------------------------------------------- private

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:200000'],
            'post_category_id' => ['nullable', 'string', 'exists:post_categories,id'],
            'cover_image' => ['nullable', 'string', 'max:255'],
            'comments_open' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Post $post, bool $full = false): array
    {
        $out = [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt,
            // Derived, so a draft and a scheduled post are told apart on the
            // list without anybody comparing a date to now.
            'state' => $post->state(),
            'published_at' => $post->published_at?->toIso8601String(),
            'category' => $post->category ? ['id' => $post->category->id, 'name' => $post->category->name] : null,
            'author' => $post->author?->name,
            'comments_open' => $post->comments_open,
            'reading_minutes' => $post->readingMinutes(),
            'comments_count' => $post->comments_count ?? null,
            'pending_comments_count' => $post->pending_comments_count ?? null,
        ];

        if ($full) {
            $out['body'] = $post->body;
            $out['plain'] = RichText::summary($post->body, 300);
            $out['tag_ids'] = $post->relationLoaded('tags') ? $post->tags->pluck('id') : [];
        }

        return $out;
    }
}
