<?php

namespace App\Models\Student;

use App\Models\Institution;
use App\Models\Institution\Period;
use App\Models\Institution\Program;
use App\Models\Invoice;
use App\Models\Master\Boarding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StudentProgram extends Model
{
    protected $table = 'student_programs';
    protected $fillable = [
        'id',
        'userId',
        'yearId',
        'institutionId',
        'periodId',
        'programId',
        'boardingId',
        'roomId',
        'registration_number',
        'registration_token',
        'registration_generated_at',
        'status',
        'createdBy',
        'updatedBy',
    ];

    protected $casts = [
        'status' => 'integer',
    ];

    public function institution(): hasOne
    {
        return $this->hasOne(Institution::class, 'id', 'institutionId');
    }
    public function period(): hasOne
    {
        return $this->hasOne(Period::class, 'id', 'periodId');
    }
    public function program(): hasOne
    {
        return $this->hasOne(Program::class, 'id', 'programId');
    }
    public function boarding(): hasOne
    {
        return $this->hasOne(Boarding::class, 'id', 'boardingId');
    }

    public function personal(): HasOne
    {
        return $this->hasOne(StudentPersonal::class, 'userId', 'userId');
    }

    public function parent(): HasOne
    {
        return $this->hasOne(StudentParent::class, 'userId', 'userId');
    }

    public function address(): HasOne
    {
        return $this->hasOne(StudentAddress::class, 'userId', 'userId');
    }
    public function verification(): HasOne
    {
        return $this->hasOne(StudentVerification::class, 'userId', 'userId');
    }

    public function invoice(): HasOne
    {
        return $this->HasOne(Invoice::class, 'userId', 'userId');
    }

    public function file(): HasOne
    {
        return $this->hasOne(StudentFile::class, 'userId', 'userId');
    }

    public function origin(): HasOne
    {
        return $this->hasOne(StudentOrigin::class, 'userId', 'userId');
    }

    public function room(): HasOne
    {
        return $this->hasOne(\App\Models\Master\Room::class, 'id', 'roomId');
    }
}
