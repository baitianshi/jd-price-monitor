function createAppUtils() {
    return {
        showToast(message, type = 'success') {
            this.toast = {show: true, message, type};
            setTimeout(() => {
                this.toast.show = false;
            }, 3000);
        }
    };
}
