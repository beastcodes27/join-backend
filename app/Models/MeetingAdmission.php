<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MeetingAdmission extends Model {
 protected $guarded = ['id'];
 
 protected function casts(): array { return []; }
 public function meeting(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Meeting::class); }
}
