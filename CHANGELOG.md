# Changelog

All notable changes to Farlo AI Alt Text Generator will be documented in this file.

## [3.0.0] - 2025-10-15

### 🎉 Major Release - Complete UI/UX Overhaul

#### Added
- **Modern Dashboard Interface** - Completely redesigned dashboard with professional aesthetics
- **ALT Library** - Dedicated tab to review, filter, and manage all ALT text
- **Quality Scoring System** - Automated QA review with 0-100 scores and improvement suggestions
- **Coverage Visualization** - Interactive donut chart showing ALT text coverage
- **Usage & Reports Tab** - Comprehensive usage tracking and CSV export
- **How to Use Guide** - Built-in documentation and workflow guidance
- **Toast Notifications** - Modern notification system with success/error feedback
- **Tooltip System** - Contextual help throughout the interface
- **Recent Activity Panel** - View and regenerate recently processed images
- **Microcard Stats** - At-a-glance metrics (Last regenerated, Top source, Dry run status)
- **Enhanced Pagination** - Improved navigation with modern, clickable page numbers
- **Empty State Design** - Engaging visuals when no data is available
- **Progress Indicators** - Real-time progress bars and logs for batch operations
- **Source Tracking** - Know how each ALT was generated (auto, bulk, AJAX, dashboard, WP-CLI, manual)
- **Error Recovery** - One-click retry buttons for failed operations
- **Mobile Responsive** - Fully optimized for all screen sizes

#### Improved
- **UI/UX Consistency** - Unified design language across all screens
- **Accessibility** - WCAG 2.1 AA compliant with enhanced keyboard navigation
- **Performance** - Optimized animations and transitions (60fps)
- **Button States** - Clearer disabled, loading, and focus states
- **Table Interactions** - Smoother hover effects with visual feedback
- **Form Inputs** - Enhanced focus states with layered shadows
- **Typography** - Improved hierarchy and readability
- **Color System** - Consistent color palette using CSS variables
- **Spacing** - Standardized spacing scale (4px grid)
- **Border Radius** - Consistent corner rounding (sm: 10px, md: 16px, lg: 24px)

#### Technical
- **CSS Custom Properties** - Centralized design tokens
- **Skeleton Loaders** - Animated placeholders for loading states
- **Utility Classes** - Reusable helper classes (`.ai-alt-hidden`, `.ai-alt-visually-hidden`)
- **Chart Sizing** - Dynamic canvas sizing via CSS variables
- **Preview Modal** - Styles moved to CSS for better caching
- **Reduced Motion Support** - Respects user motion preferences

#### Design System
- Added spacing variables (xs: 8px, sm: 16px, md: 24px, lg: 32px, xl: 40px)
- Added chart size variable (220px)
- Improved shadow system (lg, md, sm)
- Enhanced transition timing (160ms ease)

---

## [2.x.x] - Previous Versions

### Features from Earlier Versions
- OpenAI API integration
- Automatic ALT generation on upload
- Media Library bulk actions
- REST API endpoints
- WP-CLI support
- Dry run mode
- Token usage tracking
- Email notifications
- Multi-language support
- Custom prompts
- Model selection (gpt-4o-mini, gpt-4o, gpt-4.1-mini)
- Force overwrite option
- Token alert thresholds

---

## Version History

- **3.0.0** - Major UI/UX overhaul (October 2025)
- **2.x.x** - Feature additions and improvements
- **1.x.x** - Initial releases

---

## Upgrade Notes

### From 2.x to 3.0

**Database Changes:**
- No database migrations required
- All existing settings preserved
- All existing ALT text maintained
- New quality scoring runs automatically

**New Features:**
- Access new dashboard at **Media → AI ALT Text**
- Explore ALT Library tab to review all descriptions
- Check Usage & Reports for detailed metrics
- Try the new toast notifications (automatic)
- Hover over badges for helpful tooltips

**Backward Compatibility:**
- ✅ 100% backward compatible
- ✅ No breaking changes
- ✅ All REST endpoints unchanged
- ✅ All WP-CLI commands unchanged
- ✅ All hooks and filters preserved

**What's Changed:**
- Dashboard interface completely redesigned
- New tabs added (Dashboard, Usage, Library, Guide, Settings)
- Quality scoring automatically applied to all regenerations
- Source tracking added to all new generations

**Recommendations:**
1. Review your settings in the new Settings tab
2. Explore the ALT Library to see quality scores
3. Use the dashboard quick actions for bulk operations
4. Check the "How to Use" guide for the new workflow

---

## Breaking Changes

**None** - Version 3.0.0 is fully backward compatible with all 2.x versions.

---

## Future Roadmap

### Planned Features
- Batch selection in ALT Library
- Live search with debouncing
- Keyboard shortcuts
- Dark mode support
- Enhanced export options (PDF reports)
- Scheduled email reports
- Onboarding tour for new users
- Rich tooltips with formatting
- Toast notification history
- Undo functionality

### Under Consideration
- Multi-site support enhancements
- Image recognition API integration
- Custom model fine-tuning
- Team collaboration features
- Workflow automation
- Integration with popular page builders
- Advanced analytics dashboard

---

## Support

For questions about this release:
- Review the updated README.md
- Check the "How to Use" tab in the dashboard
- Visit the ALT Library for quality insights

---

**Note:** This changelog follows [Keep a Changelog](https://keepachangelog.com/) principles and adheres to [Semantic Versioning](https://semver.org/).


