<?php
namespace VanguardLTE
{

    use Illuminate\Database\Eloquent\Relations\BelongsTo;

    class StatisticAdd extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'statistics_add';
        protected $fillable = [
            'agent_in',
            'agent_out',
            'distributor_in',
            'distributor_out',
            'type_in',
            'type_out',
            'credit_in',
            'credit_out',
            'money_in',
            'money_out',
            'statistic_id',
            'user_id',
            'shop_id'
        ];
        public $timestamps = false;
        public function statistic():BelongsTo
        {
            return $this->belongsTo('VanguardLTE\Statistic');
        }
        public static function boot(): void
        {
            parent::boot();
        }
    }

}
