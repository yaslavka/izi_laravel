<?php
namespace VanguardLTE
{
    class ShopUser extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'shops_user';
        protected $fillable = [
            'shop_id',
            'user_id'
        ];
        public $timestamps = false;
        public static function boot(): void
        {
            parent::boot();
        }
        public function shop(): BelongsTo
        {
            return $this->belongsTo('VanguardLTE\Shop', 'shop_id');
        }
        public function user(): BelongsTo
        {
            return $this->belongsTo('VanguardLTE\User', 'user_id');
        }
    }

}
