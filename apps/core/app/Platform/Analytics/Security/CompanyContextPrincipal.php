<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Security;

/** Query layar mengikuti perusahaan aktif sesi; principal integrasi memakai batas tepercayanya sendiri. */
interface CompanyContextPrincipal extends AnalyticsPrincipal
{
    public function activeLegalEntityId(): ?string;
}
