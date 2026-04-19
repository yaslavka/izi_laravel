<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Events\UserCreated;
use App\Events\UserDeleted;
use App\Jobs\UpdateHierarchyUsersCache;
use App\Jobs\UpdateTreeCache;
use App\Services\BankerService;
use App\Services\RefundService;
use App\Services\WBLibService;
use Carbon\Carbon;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, JWTSubject
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        // Аутентификация
        'email',
        'username',
        'password',
        'remember_token',
        'confirmation_token',
        'auth_token',
        'session',

        // Личные данные
        'phone',
        'phone_verified',
        'sms_token',
        'sms_token_date',
        'avatar',
        'language',
        'birthday',

        // Финансы
        'currency',
        'balance',
        'shop_limit',
        'total_in',
        'total_out',
        'count_balance',

        // Статусы
        'status',
        'is_demo_agent',
        'is_blocked',
        'agreed',
        'free_demo',

        // 2FA
        'google2fa_secret',
        'google2fa_enable',

        // Рейтинг и уровни
        'rating',

        // Счётчики
        'count_tournaments',
        'count_happyhours',
        'count_refunds',
        'count_progress',
        'count_daily_entries',
        'count_invite',
        'count_welcomebonus',
        'count_smsbonus',
        'count_wheelfortune',

        // Флаги
        'tournaments',
        'happyhours',
        'refunds',
        'progress',
        'daily_entries',
        'invite',
        'welcomebonus',
        'smsbonus',
        'wheelfortune',

        // Даты
        'last_login',
        'last_online',
        'last_daily_entry',
        'last_bid',
        'last_progress',
        'last_wheelfortune',

        // Связи
        'role_id',
        'parent_id',
        'inviter_id',
        'shop_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'google2fa_secret',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'phone_verified' => 'boolean',
        'is_demo_agent' => 'boolean',
        'is_blocked' => 'boolean',
        'agreed' => 'boolean',
        'free_demo' => 'boolean',
        'google2fa_enable' => 'boolean',
        'tournaments' => 'boolean',
        'happyhours' => 'boolean',
        'refunds' => 'boolean',
        'progress' => 'boolean',
        'daily_entries' => 'boolean',
        'invite' => 'boolean',
        'welcomebonus' => 'boolean',
        'smsbonus' => 'boolean',
        'wheelfortune' => 'boolean',
        'last_login' => 'datetime',
        'last_online' => 'datetime',
        'last_daily_entry' => 'datetime',
        'last_bid' => 'datetime',
        'last_progress' => 'datetime',
        'last_wheelfortune' => 'datetime',
        'sms_token_date' => 'datetime',
        'birthday' => 'date',
        'balance' => 'decimal:2',
        'shop_limit' => 'decimal:2',
        'total_in' => 'decimal:2',
        'total_out' => 'decimal:2',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'total_balance',
    ];

    // ==================== BOOT ====================

    protected static function booted(): void
    {
        static::created(function (User $user) {
            event(new UserCreated($user));
            UpdateHierarchyUsersCache::dispatch();
            $user->update(['last_daily_entry' => Carbon::now()->subDays(2)]);
        });

        static::saved(function (User $user) {
            // Удаление эмодзи из username
            $user->updateQuietly(['username' => self::removeEmoji($user->username)]);
        });

        static::updated(function (User $user) {
            $this->normalizeUserData($user);
        });

        static::deleting(function (User $user) {
            $user->handleDeletion();
        });

        static::addGlobalScope('demoAgent', function (Builder $builder) {
            // Логика DemoAgent скоупа
        });
    }

    // ==================== RELATIONSHIPS ====================

    /**
     * Пригласивший пользователь (реферал).
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    /**
     * Приглашённые пользователи (рефералы).
     */
    public function invites(): HasMany
    {
        return $this->hasMany(User::class, 'inviter_id');
    }

    /**
     * Родительский пользователь в иерархии.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    /**
     * Подчинённые пользователи в иерархии.
     */
    public function children(): HasMany
    {
        return $this->hasMany(User::class, 'parent_id');
    }

    /**
     * Роль пользователя.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Магазин пользователя.
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Связи с магазинами (many-to-many).
     */
    public function shopUsers(): HasMany
    {
        return $this->hasMany(ShopUser::class);
    }

    /**
     * Кошелёк пользователя (активный).
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class)->where('is_active', true);
    }

    /**
     * Все кошельки пользователя.
     */
    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    /**
     * Сессии пользователя.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }

    /**
     * Активность пользователя.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(UserActivity::class);
    }

    /**
     * Ставки пользователя.
     */
    public function bets(): HasMany
    {
        return $this->hasMany(Bet::class);
    }

    // ==================== ACCESSORS & MUTATORS ====================

    /**
     * Хеширование пароля.
     */
    protected function password(): Attribute
    {
        return Attribute::make(
            set: fn(string $value) => Hash::make($value),
        );
    }

    /**
     * Форматирование дня рождения.
     */
    protected function birthday(): Attribute
    {
        return Attribute::make(
            set: fn($value) => trim($value) ?: null,
        );
    }

    /**
     * Общий баланс.
     */
    protected function getTotalBalanceAttribute(): float
    {
        return $this->balance + $this->refunds;
    }

    /**
     * URL граватара.
     */
    public function getGravatarAttribute(): string
    {
        $hash = hash('md5', strtolower(trim($this->username)));
        return sprintf('https://www.gravatar.com/avatar/%s?size=150', $hash);
    }

    /**
     * Форматированный телефон.
     */
    public function getFormattedPhoneAttribute(): string
    {
        if (preg_match('/^\+(\d{1})(\d{3})(\d{3})(\d{2})(\d{2})$/', $this->phone, $matches)) {
            return '+' . $matches[1] . '(' . $matches[2] . ') ' . $matches[3] . '-' . $matches[4] . '-' . $matches[5];
        }
        return $this->phone ?? '';
    }

    /**
     * Бейдж рейтинга.
     */
    public function getBadgeAttribute(): string
    {
        return str_pad($this->rating, 2, '0', STR_PAD_LEFT);
    }

    // ==================== STATUS CHECKS ====================

    /**
     * Активен ли пользователь.
     */
    public function isActive(): bool
    {
        return $this->status === UserStatus::ACTIVE->value;
    }

    /**
     * Заблокирован ли пользователь.
     */
    public function isBanned(): bool
    {
        return $this->status === UserStatus::BANNED->value;
    }

    /**
     * В сети ли пользователь.
     */
    public function isOnline(): bool
    {
        if (!$this->last_online) {
            return false;
        }
        return abs(now()->diffInMinutes($this->last_online)) <= 5;
    }

    /**
     * Заблокирован ли пользователь (полная проверка).
     */
    public function isBlocked(): bool
    {
        // Админ никогда не блокируется
        if ($this->role_id == 6) {
            return false;
        }

        // Глобальная блокировка сайта
        if (settings('siteisclosed')) {
            return true;
        }

        // Локальная блокировка
        if ($this->is_blocked) {
            return true;
        }

        // Блокировка родителя
        if ($this->parent && $this->parent->is_blocked) {
            return true;
        }

        // Блокировка магазина для пользователей, кассиров, менеджеров
        if ($this->hasRole([1, 2, 3])) {
            if (!$this->shop) {
                return true;
            }
            if ($this->shop->isBlocked()) {
                return true;
            }
        }

        return false;
    }

    // ==================== JWT ====================

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return ['jti' => $this->id];
    }

    // ==================== FILAMENT ====================

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasRole(['admin', 'agent', 'distributor', 'manager', 'cashier']);
    }

    // ==================== HIERARCHY METHODS ====================

    /**
     * Уровень пользователя в иерархии.
     */
    public function level(): int
    {
        return $this->role_id ?? 1;
    }

    /**
     * Доступные пользователю пользователи.
     */
    public function availableUsers(): array
    {
        $users = collect([$this->id]);

        if ($this->hasRole('admin')) {
            return User::pluck('id')->toArray();
        }

        if ($this->hasRole('agent')) {
            $distributors = User::where(['role_id' => 4, 'parent_id' => $this->id])->pluck('id');
            $others = User::where('role_id', '<=', 3)
                ->whereIn('shop_id', $this->availableShops())
                ->pluck('id');
            $users = $users->merge($distributors)->merge($others);
        }

        if ($this->hasRole('distributor')) {
            $others = User::where('role_id', '<=', 3)
                ->whereIn('shop_id', $this->shopsArray(true))
                ->pluck('id');
            $users = $users->merge($others);
        }

        if ($this->hasRole('manager')) {
            $others = User::where('role_id', '<=', 2)
                ->where('shop_id', $this->shop_id)
                ->pluck('id');
            $users = $users->merge($others);
        }

        if ($this->hasRole('cashier')) {
            $others = User::where('role_id', 1)
                ->where('shop_id', $this->shop_id)
                ->pluck('id');
            $users = $users->merge($others);
        }

        $result = $users->unique()->toArray();
        return !empty($result) ? $result : [0];
    }

    /**
     * Иерархия пользователей.
     */
    public function hierarchyUsers($shopId = null, bool $clear = false): array
    {
        $shopId = $shopId ?: $this->shop_id;

        if ($clear) {
            Cache::forget("hierarchyUsers:{$this->id}:{$shopId}");
        }

        return Cache::remember("hierarchyUsers:{$this->id}:{$shopId}", 300, function () use ($shopId) {
            $level = $this->level();
            $users = collect([$this]);

            for ($i = $level; $i >= 1; $i--) {
                foreach ($users as $user) {
                    if ($user->level() == $i) {
                        $query = User::where('parent_id', $user->id);
                        if ($shopId > 0) {
                            $query->whereHas('shopUsers', fn($q) => $q->where('shop_id', $shopId));
                        }
                        $users = $users->merge($query->get());
                    }
                }
            }

            return $users->pluck('id')->toArray();
        });
    }

    /**
     * Проверка доступности пользователя.
     */
    public function isAvailable(?User $user): bool
    {
        if (!$user) {
            return false;
        }
        return in_array($user->id, $this->availableUsers());
    }

    /**
     * Доступные магазины.
     */
    public function availableShops(bool $showZero = false): array
    {
        $shops = [$this->shop_id];

        if ($this->hasRole(['admin', 'agent', 'distributor'])) {
            if (!$this->shop_id) {
                $shops = array_merge([0], $this->shopsArray(true));
            } elseif ($showZero) {
                $shops = [0, $this->shop_id];
            }
        }

        return $shops;
    }

    /**
     * Магазины пользователя.
     */
    public function shops(bool $onlyId = false): array
    {
        if ($this->hasRole('admin')) {
            return $onlyId ? Shop::pluck('id')->toArray() : Shop::pluck('name', 'id')->toArray();
        }

        $shopIds = $this->shopUsers()->pluck('shop_id');

        if ($shopIds->isNotEmpty()) {
            return $onlyId
                ? Shop::whereIn('id', $shopIds)->pluck('id')->toArray()
                : Shop::whereIn('id', $shopIds)->pluck('name', 'id')->toArray();
        }

        return [];
    }

    /**
     * Магазины пользователя (массив).
     */
    public function shopsArray(bool $onlyId = false): array
    {
        $data = $this->shops($onlyId);
        return is_array($data) ? $data : (array) $data;
    }

    /**
     * Внутренние пользователи (на уровень ниже).
     */
    public function getInnerUsers()
    {
        $role = Role::where('id', $this->role_id - 1)->first();
        if (!$role) {
            return collect();
        }

        $userIds = $this->availableUsersByRole($role->slug);
        return User::whereIn('id', $userIds)->get();
    }

    /**
     * Доступные пользователи по роли.
     */
    public function availableUsersByRole(string $roleSlug): array
    {
        $users = $this->availableUsers();
        if (empty($users)) {
            return [];
        }

        $role = Role::where('slug', $roleSlug)->first();
        if (!$role) {
            return [];
        }

        return User::where('role_id', $role->id)
            ->whereIn('id', $users)
            ->pluck('id')
            ->toArray();
    }

    // ==================== BALANCE METHODS ====================

    /**
     * Добавление/списание баланса.
     */
    public function addBalance(
        string $type,
        float $sum,
        ?User $payer = null,
        bool $refund = true,
        string $system = 'handpay',
        bool $updateLevel = true,
               $model = null
    ): array {
        if (!in_array($type, ['add', 'out'])) {
            $type = 'add';
        }

        // Проверка на запрет вывода
        if ($type === 'out' && $system === 'handpay' && $this->hasActiveWagering()) {
            return [
                'status' => 'error',
                'message' => __('app.money_withdrawal_is_denied', ['name' => $this->username])
            ];
        }

        $shop = $this->shop;
        $sum = abs($sum);

        if (!$payer) {
            $payer = Auth::check() ? Auth::user() : $this->parent;
        }

        // Проверка прав
        $rightsCheck = $this->checkBalanceRights($payer, $type, $sum);
        if ($rightsCheck !== true) {
            return $rightsCheck;
        }

        $adjustedSum = ($type === 'out' ? -1 * $sum : $sum);

        // Обработка HappyHour
        if ($this->shouldApplyHappyHour($payer, $type, $system)) {
            return $this->processHappyHourBalance($type, $sum, $payer);
        }

        // Стандартная обработка
        return $this->processStandardBalance($type, $adjustedSum, $payer, $refund, $system, $updateLevel, $model);
    }

    /**
     * Добавление лимита.
     */
    public function addLimit(string $type, float $sum, ?User $payer = null): array
    {
        if (!in_array($type, ['add', 'out'])) {
            $type = 'add';
        }

        $sum = abs($sum);

        if (!$payer) {
            $payer = Auth::check() ? Auth::user() : $this->parent;
        }

        // Проверка прав на изменение лимита
        $rightsCheck = $this->checkLimitRights($payer, $type, $sum);
        if ($rightsCheck !== true) {
            return $rightsCheck;
        }

        $adjustedSum = ($type === 'out' ? -1 * $sum : $sum);

        // Обновление лимита родителя
        if ($payer->hasRole('agent') && $this->hasRole('distributor') ||
            $payer->hasRole('distributor') && $this->hasRole('manager')) {
            $payer->update(['shop_limit' => $payer->shop_limit - $adjustedSum]);
        }

        // Обновление лимита пользователя
        if ($this->shop_limit === null) {
            $this->update(['shop_limit' => $adjustedSum]);
        } else {
            $this->increment('shop_limit', $adjustedSum);
        }

        return [
            'status' => 'success',
            'message' => __('app.limit_updated')
        ];
    }

    /**
     * Обновление счётчика баланса.
     */
    public function updateCountBalance(float $sum, float $countBalance): float
    {
        $sum = abs($sum);
        $remaining = $sum;

        if ($sum <= 0) {
            return $this->count_balance;
        }

        // Списываем с count_balance
        if ($countBalance > 0) {
            if ($countBalance < $remaining) {
                $remaining -= $countBalance;
                $this->decrement('count_balance', $countBalance);
            } else {
                $this->decrement('count_balance', $remaining);
                return $this->count_balance;
            }
        }

        // Списываем с бонусных счётчиков
        $bonusFields = [
            'count_tournaments',
            'count_happyhours',
            'count_refunds',
            'count_progress',
            'count_daily_entries',
            'count_invite',
            'count_welcomebonus',
            'count_smsbonus',
            'count_wheelfortune'
        ];

        foreach ($bonusFields as $field) {
            $value = (float) $this->$field;
            if ($value > 0) {
                if ($remaining == 0) {
                    break;
                }
                if ($value < $remaining) {
                    $remaining -= $value;
                    $this->decrement($field, $value);
                } else {
                    $this->decrement($field, $remaining);
                    $remaining = 0;
                }
            }
        }

        return $this->count_balance;
    }

    // ==================== PRIVATE HELPERS ====================

    private static function normalizeUserData(User $user): void
    {
        if ($user->refunds <= 0) {
            $user->updateQuietly(['refunds' => 0]);
        }

        if ($user->count_balance < 0) {
            $user->updateQuietly(['count_balance' => 0]);
        }

        if ($user->balance <= 0 && $user->refunds <= 0) {
            $user->updateQuietly([
                'count_balance' => 0,
                'count_tournaments' => 0,
                'count_happyhours' => 0,
                'count_refunds' => 0,
                'count_progress' => 0,
                'count_daily_entries' => 0,
                'count_invite' => 0,
                'count_welcomebonus' => 0,
                'count_smsbonus' => 0,
                'count_wheelfortune' => 0,
                'tournaments' => false,
                'happyhours' => false,
                'refunds' => false,
                'progress' => false,
                'daily_entries' => false,
                'invite' => false,
                'welcomebonus' => false,
                'smsbonus' => false,
                'wheelfortune' => false,
            ]);
        }
    }

    private function handleDeletion(): void
    {
        $this->syncRoles([]);
        event(new UserDeleted($this));
        UpdateTreeCache::dispatch($this->hierarchyUsers());

        // Каскадное удаление связанных данных
        ShopUser::where('user_id', $this->id)->delete();
        Statistic::where('user_id', $this->id)->delete();
        Bet::where('user_id', $this->id)->delete();
        Session::where('user_id', $this->id)->delete();
        UserActivity::where('user_id', $this->id)->delete();
        Reward::where('user_id', $this->id)->orWhere('referral_id', $this->id)->delete();
    }

    private function hasActiveWagering(): bool
    {
        return $this->count_tournaments > 0 ||
            $this->count_happyhours > 0 ||
            $this->count_refunds > 0 ||
            $this->count_progress > 0 ||
            $this->count_daily_entries > 0 ||
            $this->count_invite > 0 ||
            $this->count_smsbonus > 0 ||
            $this->count_wheelfortune > 0 ||
            $this->isBanned();
    }

    private function checkBalanceRights(User $payer, string $type, float $sum): bool|array
    {
        if ($this->hasRole('manager') || $this->hasRole('cashier')) {
            return ['status' => 'error', 'message' => __('app.wrong_user')];
        }

        if (($this->hasRole('agent') || $this->hasRole('distributor')) && $payer->id != $this->parent_id) {
            return ['status' => 'error', 'message' => __('app.wrong_user')];
        }

        if ($this->hasRole('user') && $payer->shop_id != $this->shop_id) {
            return ['status' => 'error', 'message' => __('app.wrong_user')];
        }

        // Проверка баланса
        if ($payer->hasRole('cashier') && $this->hasRole('user')) {
            if ($type === 'add' && $this->shop && $this->shop->balance < $sum) {
                return [
                    'status' => 'error',
                    'message' => __('app.not_enough_money_in_the_shop', [
                        'name' => $this->shop->name,
                        'balance' => $this->shop->balance
                    ])
                ];
            }
            if ($type === 'out' && $this->balance < $sum) {
                return [
                    'status' => 'error',
                    'message' => __('app.not_enough_money_in_the_user_balance', [
                        'name' => $this->username,
                        'balance' => $this->balance
                    ])
                ];
            }
        }

        return true;
    }

    private function checkLimitRights(User $payer, string $type, float $sum): bool|array
    {
        if ($payer->hasRole('cashier') && $this->hasRole('user')) {
            if ($type === 'out' && $this->shop_limit < $sum) {
                return [
                    'status' => 'error',
                    'message' => __('app.not_enough_limit', ['limit' => $this->shop_limit])
                ];
            }
        }

        if ($payer->hasRole('agent') && $this->hasRole('distributor') ||
            $payer->hasRole('distributor') && $this->hasRole('manager')) {
            if ($type === 'add' && $payer->shop_limit < $sum) {
                return [
                    'status' => 'error',
                    'message' => __('app.not_enough_limit', ['limit' => $payer->shop_limit])
                ];
            }
            if ($type === 'out' && $this->shop_limit < $sum) {
                return [
                    'status' => 'error',
                    'message' => __('app.not_enough_limit', ['limit' => $this->shop_limit])
                ];
            }
        }

        return true;
    }

    private function shouldApplyHappyHour(User $payer, string $type, string $system): bool
    {
        return $payer->hasRole('cashier') &&
            $this->hasRole('user') &&
            $type === 'add' &&
            $system === 'handpay' &&
            $this->shop &&
            $this->shop->happyhours_active &&
            HappyHour::where(['shop_id' => $payer->shop_id, 'time' => now()->hour])->exists();
    }

    private function processHappyHourBalance(string $type, float $sum, User $payer): array
    {
        $happyhour = HappyHour::where(['shop_id' => $payer->shop_id, 'time' => now()->hour])->first();
        $multiplier = (int) str_replace('x', '', $happyhour->multiplier);
        $balance = $sum * $multiplier;

        Statistic::create([
            'user_id' => $this->id,
            'payer_id' => $this->parent_id,
            'title' => 'HH ' . $happyhour->multiplier,
            'system' => 'happyhour',
            'type' => $type,
            'sum' => $balance,
            'hh_multiplier' => $multiplier,
            'sum2' => $sum,
            'shop_id' => $this->shop_id,
        ]);

        $this->increment('balance', $balance);
        $this->increment('happyhours', $sum * $multiplier);
        $this->increment('count_happyhours', $sum * $multiplier * $happyhour->wager);
        $this->increment('total_in', $sum);

        return ['status' => 'success', 'message' => __('app.balance_updated')];
    }

    private function processStandardBalance(
        string $type,
        float $sum,
        User $payer,
        bool $refund,
        string $system,
        bool $updateLevel,
               $model
    ): array {
        $systemTitles = [
            'handpay' => 'HP',
            'invite' => 'IF',
            'progress' => 'PB',
            'tournament' => 'TB',
            'daily_entry' => 'DE',
            'refund' => 'Refund',
            'welcome_bonus' => 'WB',
            'sms_bonus' => 'SB',
            'wheelfortune' => 'WH',
        ];

        $bonusFields = [
            'invite' => 'invite',
            'progress' => 'progress',
            'tournament' => 'tournaments',
            'daily_entry' => 'daily_entries',
            'happyhour' => 'happyhours',
            'refund' => 'refunds',
            'welcome_bonus' => 'welcomebonus',
            'sms_bonus' => 'smsbonus',
            'wheelfortune' => 'wheelfortune',
        ];

        $title = $systemTitles[$system] ?? 'HP';

        Statistic::create([
            'user_id' => $this->id,
            'payer_id' => $payer->id,
            'system' => $this->hasRole(['user', 'agent']) ? $system : 'user',
            'title' => $this->hasRole(['user', 'agent']) ? $title : '',
            'type' => $type,
            'sum' => abs($sum),
            'item_id' => $model?->id,
            'shop_id' => $this->hasRole('user') ? $this->shop_id : 0,
        ]);

        if (!$this->hasRole('admin')) {
            $this->increment('balance', $sum);
        }

        if (isset($bonusFields[$system]) && $model) {
            if ($system === 'refund') {
                $this->update(['refunds' => 0]);
            } else {
                $this->increment($bonusFields[$system], $sum);
            }
            $this->increment('count_' . $bonusFields[$system], $sum * $model->wager);
        } else {
            $this->increment('count_balance', abs($sum));
            if ($type === 'out') {
                $this->increment('total_out', abs($sum));
            } else {
                $this->increment('total_in', abs($sum));
            }
        }

        // Обработка реферальных начислений
        if ($this->hasRole('user') && !isset($bonusFields[$system])) {
            if ($type === 'out') {
                $this->update(['refunds' => 0]);
            } elseif ($refund) {
                $refundAmount = app(RefundService::class)->calculate($sum, $this->shop_id, $this->rating);
                if (is_numeric($refund)) {
                    $refundAmount = ($sum * $refund) / 100;
                }
                $this->increment('refunds', $refundAmount);
            }
        }

        // Обновление баланса родителя
        if ($payer->hasRole('agent') && $this->hasRole('distributor') ||
            $payer->hasRole('distributor') && $this->hasRole('manager')) {
            $payer->update(['balance' => $payer->balance - $sum]);
        }

        // Обновление баланса магазина
        if ($payer->hasRole('cashier') && $this->hasRole('user') && !isset($bonusFields[$system])) {
            $shop = $this->shop;
            $shop->update(['balance' => $shop->balance - $sum]);

            $openShift = OpenShift::where([
                'shop_id' => $payer->shop_id,
                'user_id' => $payer->id,
                'end_date' => null
            ])->first();

            if ($openShift) {
                $type === 'out'
                    ? $openShift->increment('balance_in', abs($sum))
                    : $openShift->increment('balance_out', abs($sum));
                $openShift->increment('transfers');
            }
        }

        return ['status' => 'success', 'message' => __('app.balance_updated')];
    }

    private static function removeEmoji(string $text): string
    {
        return preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', '', $text);
    }
}
