/** Akun pengguna di organisasi ini, seperti dijawab `GET core-members`. */
export type Account = {
    membership_id: string;
    name: string;
    email: string;
};

/** Anggota yang bisa ditautkan, beserta pekerja yang sudah memegang akunnya, bila ada. */
export type AccountOption = Account & {
    linked_worker_id: string | null;
};

/** Satu baris `GET workers`. */
export type Worker = {
    id: string;
    personnel_number: string;
    name: string;
    email: string | null;
    core_membership_id: string | null;
    version: number;
    /** Akun yang tertaut; `null` bila belum tertaut atau akunnya sudah tidak aktif. */
    account: Account | null;
};

export const PERMISSION_CREATE_WORKER = 'human-resources.workers.create';
export const PERMISSION_LINK_ACCOUNT =
    'human-resources.core-account-link.invoke';
