<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
class User extends Authenticatable {
 use HasApiTokens, HasFactory, Notifiable;
 protected $fillable = ['firebase_uid','name','email','avatar','status'];
 protected $hidden = ['password','remember_token','firebase_uid'];
 public function meetings(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(Meeting::class,'host_id'); }
}
