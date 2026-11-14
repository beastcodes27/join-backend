<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MeetingParticipant extends Model {
 protected $guarded = ['id'];
 
 protected function casts(): array { return ['joined_at'=>'datetime','left_at'=>'datetime','lease_until'=>'datetime','removed_at'=>'datetime']; }
 public function meeting(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Meeting::class); }
}
