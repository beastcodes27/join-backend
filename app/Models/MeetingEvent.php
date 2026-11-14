<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MeetingEvent extends Model {
 protected $guarded = ['id'];
 public $timestamps = false;
 protected function casts(): array { return ['payload'=>'array']; }
 public function meeting(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Meeting::class); }
}
