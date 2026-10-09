<?php

namespace App\Models\Platform;

use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedbackReply extends Model
{
    use HasUuid;

    protected $fillable = ['feedback_id', 'authored_by', 'author_type', 'message'];

    /** @return BelongsTo<PlatformIncident, $this> */
    public function feedback(): BelongsTo
    {
        return $this->belongsTo(PlatformIncident::class, 'feedback_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authored_by');
    }
}
