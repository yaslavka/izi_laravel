<?php
namespace VanguardLTE
{
    class UserActivity extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'user_activity';
        public $timestamps = false;
        public static function boot(): void
        {
            parent::boot();
        }
        public function userdata()
        {
            return $this->hasOne('VanguardLTE\User', 'id', 'user_id');
        }
    }

}
