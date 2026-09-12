import type { StudentProfileData } from '@/types/student-profile';

const DEMO_STORAGE_KEY =
    'lcms-demo-student-profile';

const LIVE_OVERRIDE_KEY =
    'lcms-student-profile-ui-overrides';

export const defaultStudentProfile: StudentProfileData =
    {
        fullName:
            'Mohammad Znaid',

        studentId:
            'STU-2026-0842',

        roleLabel:
            'Student',

        email:
            'hussam.a@alhilal-center.com',

        phone:
            '+970 50 123 4567',

        address:
            'Al-Rimal District, Gaza City, Palestine',

        enrolledOn:
            'Sep 1, 2026',

        centerName:
            'Al-Hilal Language Center',

        branchName:
            'Gaza – Palestine',

        activeCourses: 2,

        displayLanguage:
            'English',
    };

export type StudentProfileUiOverrides =
    Partial<
        Pick<
            StudentProfileData,
            | 'email'
            | 'phone'
            | 'address'
            | 'displayLanguage'
            | 'photoDataUrl'
        >
    >;

export function readDemoStudentProfile(): StudentProfileData {
    if (
        typeof window ===
        'undefined'
    ) {
        return defaultStudentProfile;
    }

    try {
        const raw =
            window.localStorage.getItem(
                DEMO_STORAGE_KEY,
            );

        if (!raw) {
            return defaultStudentProfile;
        }

        return {
            ...defaultStudentProfile,

            ...(JSON.parse(
                raw,
            ) as Partial<StudentProfileData>),
        };
    } catch {
        return defaultStudentProfile;
    }
}

export function saveDemoStudentProfile(
    profile: StudentProfileData,
) {
    if (
        typeof window ===
        'undefined'
    ) {
        return;
    }

    try {
        window.localStorage.setItem(
            DEMO_STORAGE_KEY,
            JSON.stringify(
                profile,
            ),
        );
    } catch {
        // Demo persistence is non-critical.
    }
}

export function readLiveStudentProfileOverrides(): StudentProfileUiOverrides {
    if (
        typeof window ===
        'undefined'
    ) {
        return {};
    }

    try {
        const raw =
            window.localStorage.getItem(
                LIVE_OVERRIDE_KEY,
            );

        if (!raw) {
            return {};
        }

        return JSON.parse(
            raw,
        ) as StudentProfileUiOverrides;
    } catch {
        return {};
    }
}

export function saveLiveStudentProfileOverrides(
    overrides: StudentProfileUiOverrides,
) {
    if (
        typeof window ===
        'undefined'
    ) {
        return;
    }

    try {
        window.localStorage.setItem(
            LIVE_OVERRIDE_KEY,
            JSON.stringify(
                overrides,
            ),
        );
    } catch {
        // UI preferences remain usable without persistence.
    }
}