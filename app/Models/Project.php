<?php

namespace App\Models;

use App\Contracts\Commentable;
use App\Models\Concerns\HasComments;
use App\Support\Comments\ShareContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model implements Commentable
{
    use HasComments;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'title',
        'description',
        'parent_project_id',
        'elixirr_client_slug',
        'elixirr_project_slug',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'parent_project_id');
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Project::class, 'parent_project_id');
    }

    public function isElixirrClientRoot(): bool
    {
        return $this->parent_project_id === null
            && $this->elixirr_client_slug !== null
            && $this->elixirr_project_slug === null;
    }

    /**
     * @return BelongsToMany<Thought, $this>
     */
    public function thoughts(): BelongsToMany
    {
        return $this->belongsToMany(Thought::class, 'project_thought')
            ->using(ProjectThought::class)
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /**
     * @return HasMany<ProjectShare, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(ProjectShare::class);
    }

    public function commentableOwnerId(): ?int
    {
        return $this->user_id;
    }

    /**
     * v1: only the project owner can comment on a project. Public project
     * comments are out of scope.
     */
    public function authorizeCommentCreation(?User $user, ?ShareContext $shareContext): bool
    {
        return $user !== null && $this->user_id === $user->id;
    }
}
