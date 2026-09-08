function createTagsModule() {
    return {
        tags: [],
        filterTag: '',
        showTagManager: false,
        newTagName: '',
        newTagColor: '#3B82F6',
        
        tagColors: [
            '#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6',
            '#EC4899', '#06B6D4', '#84CC16', '#F97316', '#6366F1'
        ],
        
        async loadTags() {
            try {
                const res = await fetch(BASE_PATH + '/api/tags.php');
                const data = await res.json();
                if (data.success) {
                    this.tags = data.data.tags || [];
                }
            } catch (e) {
                console.error('加载标签失败', e);
            }
        },
        
        async addTag() {
            if (!this.newTagName.trim()) {
                this.showToast('请输入标签名称', 'error');
                return;
            }
            
            try {
                const res = await fetch(BASE_PATH + '/api/tags.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        name: this.newTagName.trim(),
                        color: this.newTagColor,
                        csrf_token: this.csrfToken
                    })
                });
                const data = await res.json();
                
                if (data.success) {
                    this.showToast('标签添加成功');
                    this.newTagName = '';
                    this.newTagColor = '#3B82F6';
                    await this.loadTags();
                } else {
                    this.showToast(data.message || '添加失败', 'error');
                }
            } catch (e) {
                this.showToast('添加失败', 'error');
            }
        },
        
        async deleteTag(id) {
            if (!confirm('确定删除此标签？')) return;
            
            try {
                const res = await fetch(BASE_PATH + '/api/tags.php?id=' + id, {
                    method: 'DELETE',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({csrf_token: this.csrfToken})
                });
                const data = await res.json();
                
                if (data.success) {
                    this.showToast('标签已删除');
                    await this.loadTags();
                } else {
                    this.showToast(data.message || '删除失败', 'error');
                }
            } catch (e) {
                this.showToast('删除失败', 'error');
            }
        },
        
        selectTagColor(color) {
            this.newTagColor = color;
        },
        
        getTagColor(tagName) {
            const tag = this.tags.find(t => t.name === tagName);
            return tag ? tag.color : '#6B7280';
        },
        
        parseTags(tagsStr) {
            if (!tagsStr) return [];
            return tagsStr.split(',').map(t => t.trim()).filter(t => t);
        },
        
        tagsToString(tags) {
            return tags.join(',');
        }
    };
}
