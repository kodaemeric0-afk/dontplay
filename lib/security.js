'use strict';

const { body, param, query, validationResult } = require('express-validator');
const sanitizeHtml = require('sanitize-html');

/**
 * Middleware d'erreur de validation
 */
function handleValidationErrors(req, res, next) {
  const errors = validationResult(req);
  if (!errors.isEmpty()) {
    return res.status(400).json({
      error: 'Validation error',
      details: errors.array().map(e => ({ field: e.param, msg: e.msg }))
    });
  }
  next();
}

/**
 * Sanitizer de contenu (XSS prevention)
 */
function sanitizeContent(content) {
  if (typeof content !== 'string') return content;
  return sanitizeHtml(content, {
    allowedTags: [],
    allowedAttributes: {},
    disallowedTagsMode: 'discard',
  });
}

/**
 * Validation schemas pour routes courants
 */
const validators = {
  // Auth
  register: [
    body('username')
      .isLength({ min: 3, max: 20 })
      .withMessage('Username must be 3-20 chars')
      .matches(/^[a-zA-Z0-9_-]+$/)
      .withMessage('Username: letters, numbers, - and _ only')
      .trim(),
    body('password')
      .isLength({ min: 12 })
      .withMessage('Password must be at least 12 chars')
      .matches(/[A-Z]/)
      .withMessage('Password must include uppercase')
      .matches(/[0-9]/)
      .withMessage('Password must include numbers')
      .matches(/[!@#$%^&*()\-_=+\[\]{};:'",.<>/?`~\\|]/)
      .withMessage('Password must include special char'),
    body('confirmPassword')
      .custom((value, { req }) => value === req.body.password)
      .withMessage('Passwords must match'),
  ],

  // Redirections
  createRedirect: [
    body('slug')
      .isLength({ min: 1, max: 50 })
      .withMessage('Slug required, max 50 chars')
      .matches(/^[a-z0-9\-_]+$/)
      .withMessage('Slug: lowercase, numbers, dash, underscore only')
      .trim(),
    body('domainName')
      .optional()
      .isLength({ min: 5, max: 255 })
      .withMessage('Domain max 255 chars')
      .matches(/^[a-z0-9.-]+$/)
      .withMessage('Invalid domain format')
      .trim(),
    body('destination')
      .isLength({ min: 5, max: 2048 })
      .withMessage('Destination required, max 2048 chars')
      .trim(),
    body('destinationType')
      .isIn(['domain', 'external', 'page'])
      .withMessage('Invalid destination type'),
    body('redirectType')
      .isIn(['301', '302'])
      .withMessage('Redirect must be 301 or 302'),
  ],

  // SMS Campaign
  createSMSCampaign: [
    body('name')
      .isLength({ min: 1, max: 100 })
      .withMessage('Campaign name required, max 100 chars')
      .custom(v => !/[<>]/.test(v))
      .withMessage('Campaign name: no HTML tags')
      .trim(),
    body('message')
      .isLength({ min: 1, max: 160 })
      .withMessage('SMS message required, max 160 chars'),
    body('contacts')
      .optional()
      .isArray()
      .withMessage('Contacts must be array'),
    body('text')
      .optional()
      .isLength({ max: 10000 })
      .withMessage('Text max 10000 chars'),
    body('speed')
      .optional()
      .isInt({ min: 1, max: 100 })
      .withMessage('Speed: 1-100'),
  ],

  // Phone number
  phoneNumber: [
    body('to')
      .isMobilePhone('any')
      .withMessage('Invalid phone number'),
  ],
};

module.exports = {
  handleValidationErrors,
  sanitizeContent,
  validators,
};
