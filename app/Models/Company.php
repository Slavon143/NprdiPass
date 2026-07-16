<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Concerns\HasUuid;
use App\Models\Passports\ProductPassport;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property CompanyStatus $status
 * @property array<string, mixed>|null $settings
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'name',
        'legal_name',
        'organization_number',
        'country_code',
        'billing_email',
        'status',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'settings' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsToMany<User, $this, CompanyMembership, 'pivot'>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(CompanyMembership::class)
            ->withPivot(['id', 'role', 'is_owner', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<CompanyMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class);
    }

    /**
     * @return HasMany<CompanyInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(CompanyInvitation::class);
    }

    /**
     * @return HasMany<PersonalAccessToken, $this>
     */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function productVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function attributeDefinitions(): HasMany
    {
        return $this->hasMany(AttributeDefinition::class);
    }

    public function attributeOptions(): HasMany
    {
        return $this->hasMany(AttributeOption::class);
    }

    public function productMedia(): HasMany
    {
        return $this->hasMany(ProductMedia::class);
    }

    public function productPassports(): HasMany
    {
        return $this->hasMany(ProductPassport::class);
    }

    public function productDocuments(): HasMany
    {
        return $this->hasMany(ProductDocument::class);
    }
}
