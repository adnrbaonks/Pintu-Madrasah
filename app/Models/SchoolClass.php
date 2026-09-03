<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SchoolClass extends Model
{
    protected $table = 'classes';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['grade_level', 'major_name'];
    protected function casts(): array { return ['id' => 'string']; }
    protected static function boot(): void {
        parent::boot();
        static::creating(fn($m) => $m->id ??= (string) Str::uuid());
    }
    public function rombels() { return $this->hasMany(Rombel::class, 'class_id'); }
}
