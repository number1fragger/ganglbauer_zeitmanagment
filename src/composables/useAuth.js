import {ref} from 'vue';
import {jwtDecode} from 'jwt-decode';
import {getToken} from '@/services/authservice';

const isLoggedIn = ref(false);
const isAdmin = ref(false);

export function useAuth() {

    const updateLoginStatus = () => {
        isLoggedIn.value = !!getToken();
    };

    const updateAdminStatus = () => {
        const token = getToken();
        if (token) {
            try {
                const decoded = jwtDecode(token);
                isAdmin.value = decoded.sub === 'admin';
            } catch {
                isAdmin.value = false;
            }
        } else {
            isAdmin.value = false;
        }
    };

    return {
        isLoggedIn,
        isAdmin,
        updateLoginStatus,
        updateAdminStatus
    };
}
