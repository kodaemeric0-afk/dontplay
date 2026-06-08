'use strict';

const SmsStorage = require('./storage');
const PhoneParser = require('./PhoneParser');
const MessageVariableEngine = require('./MessageVariableEngine');
const SMSProviderManager = require('./SMSProviderManager');
const SMSSender = require('./SMSSender');

module.exports = {
  SmsStorage,
  PhoneParser,
  MessageVariableEngine,
  SMSProviderManager,
  SMSSender,
  createRuntime(filePath) {
    const storage = new SmsStorage(filePath);
    const providerManager = new SMSProviderManager(storage);
    const sender = new SMSSender(storage, providerManager);
    return { storage, providerManager, sender, parser: new PhoneParser(), engine: new MessageVariableEngine() };
  },
};