import type { StudentProfileData } from '@/types/student-profile';

const STORAGE_KEY = 'lcms-demo-student-profile';

export const defaultStudentProfile: StudentProfileData = {
    fullName: 'Mohammad Znaid',
    studentId: 'STU-2024-0842',
    roleLabel: 'Student',
    email: 'hussam.a@alhilal-center.com',
    phone: '+966 50 123 4567',
    address: 'Olaya District, Riyadh 12211, Saudi Arabia',
    enrolledOn: 'Sep 1, 2024',
    centerName: 'Al-Hilal Language Center',
    branchName: 'Riyadh – Main Branch',
    activeCourses: 2,
    displayLanguage: 'English',
};

export function readDemoStudentProfile(): StudentProfileData {
    if (typeof window === 'undefined') {
        return defaultStudentProfile;
    }

    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);

        if (!raw) {
            return defaultStudentProfile;
        }

        return {
            ...defaultStudentProfile,
            ...(JSON.parse(raw) as Partial<StudentProfileData>),
        };
    } catch {
        return defaultStudentProfile;
    }
}

export function saveDemoStudentProfile(profile: StudentProfileData) {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify(profile),
        );
    } catch {
        // Demo persistence is non-critical. The UI remains usable
        // even if browser storage is unavailable.
    }
}
