<template>
  <div class="login-container">
    <div class="login-card">
      <h1>Login</h1>

      <div class="form-group">
        <label>Username</label>
        <input v-model="username" type="text" placeholder="Enter your username" />
      </div>

      <div class="form-group">
        <label>Password</label>
        <input v-model="password" type="password" placeholder="Enter your password" />
      </div>

      <button @click="doLogin" class="btn-primary">Log in</button>

      <div class="footer-link">
        <router-link to="/register">Don't have an account? Sign up!</router-link>
      </div>
    </div>
  </div>
</template>

<script>
import { login } from "@/services/authService";
import { useAuth } from "@/composables/useAuth";

export default {
  setup() {
    const { updateLoginStatus } = useAuth();
    return { updateLoginStatus };
  },
  data() {
    return {
      username: "",
      password: ""
    };
  },
  methods: {
    async doLogin() {
      try {
        await login(this.username, this.password);
        this.updateLoginStatus();
        this.$router.push("/");
      } catch (e) {
        alert("Login failed");
      }
    }
  }
};
</script>
