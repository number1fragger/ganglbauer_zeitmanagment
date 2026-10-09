const URL = 'http://localhost:8080';

export async function login(username, password) {
    const res = await fetch(`${URL}/auth/login`, {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({username, password})
    });

    const data = await res.json();

    if (!res.ok || !data.success) {
        throw new Error(data.error || "Login failed");
    }

    saveToken(data.token);
    return data;
}

export async function logout() {
    const token = getToken();

    const res = await fetch(`${URL}/auth/logout`, {
        method: "POST",
        headers: {
            "Authorization": `Bearer ${token}`
        }
    });

    clearToken();

    return await res.json();
}

export const saveToken = (token) => {
    localStorage.setItem("jwt", token)
    console.log(token);
}
export const getToken = () => localStorage.getItem("jwt");
export const clearToken = () => localStorage.removeItem("jwt");
